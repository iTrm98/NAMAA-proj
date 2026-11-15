<?php

namespace Tests\Feature\Sprint63;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\VerificationStatus;
use App\Http\Resources\DetectionResource;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use App\Services\ScenarioService;
use App\Support\MapBounds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6.3 (BACKEND-2): checked-zone gate, archive-set and sim setup commands, actions, scenarios, map bounds. */
class SimParksTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
    }

    private function park(array $attrs = []): Park
    {
        return Park::factory()->create(['company_id' => $this->company->id] + $attrs);
    }

    public function test_require_checked_zone_hides_unchecked_parks_from_the_list(): void
    {
        config(['namaa.parks.require_checked_zone' => true]);
        $zone = ['type' => 'Polygon', 'coordinates' => [[[39.8, 21.4], [39.81, 21.4], [39.81, 21.41], [39.8, 21.4]]]];
        $shown = $this->park(['green_zone' => $zone, 'verification_status' => VerificationStatus::Verified]);
        $this->park(['green_zone' => $zone]);
        $this->park(['verification_status' => VerificationStatus::Verified]);
        $this->park(['green_zone' => $zone, 'verification_status' => VerificationStatus::Verified, 'site_type' => 'demo_stand', 'is_test' => true]);

        $ids = collect($this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()->json('data'))->pluck('id');
        $this->assertSame([$shown->id], $ids->all());

        config(['namaa.parks.require_checked_zone' => false]);
        $this->assertCount(3, $this->asUser($this->admin)->getJson('/api/admin/parks')->json('data'));
    }

    public function test_archive_set_dry_run_matches_names_and_reports_not_found(): void
    {
        config(['namaa.parks.archive_set' => ['Zainab Al-Hilaliyah Walkway', "Al-Tan'eem garden", 'Nowhere Park']]);
        $zainab = $this->park(['name_en' => "Umm Al-Mu'minin Zainab Al-Hilaliyah Park and Walkway"]);
        $walk = $this->park(['name_en' => "Al-Tan'eem Walkway"]);
        $garden = $this->park(['name_en' => "Al-Tan'eem garden (name to confirm)"]);

        $this->artisan('namaa:parks:archive-set', ['--dry-run' => true])
            ->expectsOutputToContain('NOT FOUND')
            ->expectsOutputToContain('2 of 3 name(s) archived')
            ->assertSuccessful();

        $this->assertNull($zainab->fresh()->archived_at);
        $this->assertNull($garden->fresh()->archived_at);

        $this->artisan('namaa:parks:archive-set')->assertSuccessful();
        $this->assertNotNull($zainab->fresh()->archived_at);
        $this->assertNotNull($garden->fresh()->archived_at);
        $this->assertNull($walk->fresh()->archived_at, 'the walkway is not the garden');
    }

    public function test_sim_setup_dry_run_changes_nothing_then_creates_pump_nodes_and_moves_sim_cam(): void
    {
        config(['namaa.parks.sim_set' => [['name' => "Al-Ju'ranah", 'role' => 'dry'], ['name' => 'King Abdullah', 'role' => 'leak']], 'namaa.parks.sim_camera_park' => 'King Abdullah']);
        $juranah = $this->park(['name_en' => "Al-Ju'ranah Park"]);
        $king = $this->park(['name_en' => 'King Abdullah Park']);
        $adel = $this->park(['name_en' => 'Al Adel Park', 'source' => DataSource::Simulated, 'monitoring_mode' => 'simulated']);
        $cam = Node::factory()->camera()->simulated()->create(['park_id' => $adel->id, 'name' => 'Sim Cam']);

        $this->artisan('namaa:sim:setup-parks', ['--dry-run' => true])->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(0, Node::where('type', NodeType::Sensor)->count());
        $this->assertSame($adel->id, $cam->fresh()->park_id);
        $this->assertNull($juranah->fresh()->demo_role);

        $this->artisan('namaa:sim:setup-parks')->assertSuccessful();
        foreach ([$juranah, $king] as $park) {
            $node = Node::where('park_id', $park->id)->where('type', NodeType::Sensor)->where('source', DataSource::Simulated->value)->first();
            $this->assertNotNull($node, 'simulated sensor node');
            $this->assertTrue($node->has_pump);
            $this->assertNotNull($node->expected_flow_l_min);
        }
        $this->assertSame($king->id, $cam->fresh()->park_id);
        $this->assertSame('dry', $juranah->fresh()->demo_role);
        $this->assertSame('leak', $king->fresh()->demo_role);
    }

    public function test_park_detail_actions_per_state(): void
    {
        $park = $this->park(['source' => DataSource::Simulated, 'monitoring_mode' => 'simulated']);
        $node = Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'pump_target' => false]);
        $url = "/api/admin/parks/{$park->id}";

        $idle = $this->asUser($this->admin)->getJson($url)->assertOk()->json('data.actions');
        $this->assertNull($idle['primary']);
        $this->assertContains('water_now', array_column($idle['menu'], 'key'));

        $dry = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $node->id, 'type' => AlertType::Dry]);
        $a = $this->asUser($this->admin)->getJson($url)->json('data.actions');
        $this->assertSame(['water_now', $dry->id, $node->id], [$a['primary']['key'], $a['primary']['alert_id'], $a['primary']['node_id']]);
        $this->assertContains('send_worker', array_column($a['menu'], 'key'));
        $this->assertContains('toggle_auto', array_column($a['menu'], 'key'));

        $dry->update(['status' => 'resolved', 'resolved_at' => now()]);
        $leak = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $node->id, 'type' => AlertType::Leak]);
        $this->assertSame('send_worker', $this->asUser($this->admin)->getJson($url)->json('data.actions.primary.key'));

        Task::factory()->create(['park_id' => $park->id, 'alert_id' => $leak->id, 'company_id' => $this->company->id]);
        $this->assertSame('view_task', $this->asUser($this->admin)->getJson($url)->json('data.actions.primary.key'));

        $node->update(['pump_target' => true]);
        $this->assertSame('stop_pump', $this->asUser($this->admin)->getJson($url)->json('data.actions.primary.key'));
    }

    public function test_simulated_park_pump_and_auto_controls_work(): void
    {
        $park = $this->park(['source' => DataSource::Simulated, 'monitoring_mode' => 'simulated']);
        $node = Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'pump_target' => false]);

        $this->asUser($this->admin)->postJson("/api/admin/parks/{$park->id}/pump", ['on' => true])->assertOk();
        $this->assertTrue((bool) $node->fresh()->pump_target);
        $this->asUser($this->admin)->patchJson("/api/admin/parks/{$park->id}", ['auto_irrigation' => false])->assertOk();
        $this->assertFalse((bool) $park->fresh()->auto_irrigation);
    }

    public function test_story_lists_the_panel_scenarios_and_they_run_on_simulated_parks(): void
    {
        config(['namaa.demo_mode' => true, 'namaa.camera.samples_path' => sys_get_temp_dir().'/none-'.uniqid()]);
        $dryPark = $this->park(['source' => DataSource::Simulated, 'monitoring_mode' => 'simulated', 'demo_role' => 'dry']);
        $leakPark = $this->park(['source' => DataSource::Simulated, 'monitoring_mode' => 'simulated', 'demo_role' => 'leak']);
        $dryNode = Node::factory()->simulated()->withPump()->create(['park_id' => $dryPark->id, 'name' => 'Sim 1']);
        $leakNode = Node::factory()->simulated()->withPump()->create(['park_id' => $leakPark->id, 'name' => 'Sim 2']);

        $rows = collect($this->asUser($this->admin)->getJson('/api/admin/demo/story')->assertOk()->json('scenarios'))->keyBy('name');
        $this->assertSame(['reset', 'dry', 'heat', 'leak', 'blockage', 'offline', 'litter', 'recover'], $rows->keys()->all());
        $this->assertSame($dryPark->id, $rows['dry']['park_id']);
        $this->assertSame($leakNode->id, $rows['leak']['node_id']);
        $this->assertFalse($rows['litter']['available'], 'no camera / photos');

        $svc = app(ScenarioService::class);
        foreach (['reset', 'dry', 'heat', 'leak', 'blockage', 'offline', 'recover'] as $name) {
            $this->assertSame($name, $svc->run($name, $this->admin, $this->company->id)['scenario']);
        }
        $this->assertSame($dryNode->id, $svc->run('dry', $this->admin, $this->company->id)['node_id']);
    }

    public function test_detection_of_a_sample_photo_is_labelled_sample(): void
    {
        $detection = Detection::factory()->create(['origin' => 'scenario']);
        $this->assertSame('sample', (new DetectionResource($detection))->resolve(request())['origin']);
    }

    public function test_demo_status_map_has_basemap_and_bounds_with_margin(): void
    {
        config(['namaa.map.basemap' => 'esri']);
        $this->park(['lat' => 21.4, 'lng' => 39.8, 'polygon' => ['type' => 'Polygon', 'coordinates' => [[[39.8, 21.4], [39.801, 21.4], [39.801, 21.401], [39.8, 21.4]]]]]);

        $map = $this->getJson('/api/demo/status')->assertOk()->json('map');
        $this->assertSame('esri', $map['basemap']);
        [$w, $s, $e, $n] = $map['bounds'];
        $this->assertEqualsWithDelta(39.8 - 8 / (111.32 * cos(deg2rad(21.4))), $w, 0.002);
        $this->assertEqualsWithDelta(21.4 + 8 / 111.32, $n, 0.002);
        $this->assertTrue($w < $e && $s < $n);

        config(['namaa.map.basemap' => 'nonsense']);
        $this->assertSame('maptiler', MapBounds::forShownParks()['basemap']);
    }
}
