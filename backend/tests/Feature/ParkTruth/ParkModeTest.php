<?php

namespace Tests\Feature\ParkTruth;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\MonitoringMode;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\SiteType;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use App\Services\ScenarioService;
use App\Services\Simulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Sprint 6.2, Phase A: monitoring modes, demo roles, archive, stand setup, story endpoint. */
class ParkModeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.demo_mode' => true]);
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
    }

    private function simPark(string $name, ?string $role = null, string $node = 'Sim 1'): Park
    {
        $park = Park::factory()->simulated()->create(['company_id' => $this->company->id, 'name_en' => $name, 'demo_role' => $role, 'status' => ParkStatus::Ok]);
        Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'name' => $node]);

        return $park;
    }

    public function test_cap_of_four_simulated_parks_per_company(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $i => $n) {
            $this->simPark("Park $n", null, 'Sim '.($i + 1));
        }
        $fifth = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'Fifth', 'monitoring_mode' => MonitoringMode::SatelliteOnly]);

        $this->artisan('namaa:parks:set-mode', ['park' => 'Fifth', 'mode' => 'simulated'])->assertFailed();
        $this->assertSame(MonitoringMode::SatelliteOnly, $fifth->refresh()->monitoring_mode);

        // Another company does not count against the cap.
        $other = Park::factory()->create(['monitoring_mode' => MonitoringMode::SatelliteOnly, 'name_en' => 'Other company park']);
        $this->artisan('namaa:parks:set-mode', ['park' => (string) $other->id, 'mode' => 'simulated'])->assertSuccessful();
        $this->assertSame(MonitoringMode::Simulated, $other->refresh()->monitoring_mode);
    }

    public function test_set_mode_simulated_creates_a_sim_node_and_takes_the_role(): void
    {
        $holder = $this->simPark('Holder', 'dry', 'Sim 1');
        $park = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'Newcomer', 'monitoring_mode' => MonitoringMode::SatelliteOnly, 'status' => ParkStatus::NoSensor]);

        $this->artisan('namaa:parks:set-mode', ['park' => 'Newcomer', 'mode' => 'simulated', '--demo-role' => 'dry'])->assertSuccessful();

        $park->refresh();
        $node = $park->nodes()->first();
        $this->assertSame(MonitoringMode::Simulated, $park->monitoring_mode);
        $this->assertSame(DataSource::Simulated, $park->source);
        $this->assertTrue($park->auto_irrigation);
        $this->assertSame('dry', $park->demo_role);
        $this->assertNull($holder->refresh()->demo_role, 'the role moves from its previous holder');
        $this->assertSame('Sim 2', $node->name);
        $this->assertTrue($node->has_pump);
        $this->assertSame(DataSource::Simulated, $node->source);
        $this->assertNull($node->detached_at);
        $this->assertTrue(ActivityLog::where('action', 'park.mode_changed')->where('subject_id', $park->id)->exists());
    }

    public function test_satellite_only_detaches_resolves_cancels_and_keeps_ndvi_drop(): void
    {
        $park = $this->simPark('Going dark', 'leak');
        $node = $park->nodes()->first();
        $node->update(['pump_target' => true]);
        $leak = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $node->id, 'type' => AlertType::Leak]);
        $ndvi = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::NdviDrop]);
        $task = Task::factory()->create(['park_id' => $park->id, 'alert_id' => $leak->id, 'company_id' => $this->company->id, 'status' => TaskStatus::Open]);
        Cache::forever(ScenarioService::cacheKey($node->id, 'leak'), true);
        $readings = Reading::where('node_id', $node->id)->count();

        $this->artisan('namaa:parks:set-mode', ['park' => (string) $park->id, 'mode' => 'satellite_only', '--dry-run' => true])->assertSuccessful();
        $this->assertNull($node->refresh()->detached_at, 'a dry run changes nothing');
        $this->assertSame(MonitoringMode::Simulated, $park->refresh()->monitoring_mode);

        $this->artisan('namaa:parks:set-mode', ['park' => (string) $park->id, 'mode' => 'satellite_only'])->assertSuccessful();

        $park->refresh();
        $this->assertSame(MonitoringMode::SatelliteOnly, $park->monitoring_mode);
        $this->assertSame(DataSource::Real, $park->source);
        $this->assertNull($park->demo_role);
        $this->assertSame(ParkStatus::NoSensor, $park->status);
        $this->assertNotNull($node->refresh()->detached_at);
        $this->assertFalse($node->pump_target);
        $this->assertFalse((bool) Cache::get(ScenarioService::cacheKey($node->id, 'leak')));
        $this->assertSame('resolved', $leak->refresh()->status->value);
        $this->assertSame('open', $ndvi->refresh()->status->value);
        $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status);
        $this->assertSame($readings, Reading::where('node_id', $node->id)->count());
        $this->assertTrue(ActivityLog::where('action', 'alert.resolved')->where('subject_id', $leak->id)->get()
            ->contains(fn ($l) => ($l->diff['after']['reason'] ?? null) === 'mode_changed'));
    }

    public function test_satellite_only_parks_get_no_readings_alerts_or_tasks(): void
    {
        $live = $this->simPark('Live', null, 'Sim 1');
        $dark = $this->simPark('Dark', null, 'Sim 2');
        $this->artisan('namaa:parks:set-mode', ['park' => (string) $dark->id, 'mode' => 'satellite_only'])->assertSuccessful();

        $count = app(Simulator::class)->tick();

        $this->assertSame(1, $count);
        $this->assertSame(0, Reading::where('node_id', $dark->nodes()->first()->id)->count());
        $this->assertSame(1, Reading::where('node_id', $live->nodes()->first()->id)->count());
        $this->artisan('namaa:check-offline')->run();
        $this->assertSame(0, Alert::where('park_id', $dark->id)->where('type', AlertType::Offline)->count());
        $this->assertSame(ParkStatus::NoSensor, $dark->refresh()->status);
    }

    public function test_real_sensors_needs_real_nodes(): void
    {
        $park = $this->simPark('Only sim');
        $this->artisan('namaa:parks:set-mode', ['park' => (string) $park->id, 'mode' => 'real_sensors'])->assertFailed();
        $this->assertSame(MonitoringMode::Simulated, $park->refresh()->monitoring_mode);
    }

    public function test_reset_follows_demo_roles_and_falls_back_without_them(): void
    {
        $adel = $this->simPark('Adel', null, 'Sim 1');
        $new = $this->simPark('New dry', 'dry', 'Sim 5');
        $adel->nodes()->first()->update(['last_moisture_pct' => 50]);
        $new->nodes()->first()->update(['last_moisture_pct' => 50]);
        $scenarios = app(ScenarioService::class);

        $scenarios->run('reset', $this->admin, $this->company->id);
        $this->assertEquals(35.0, Cache::get('namaa:sim:'.$new->nodes()->first()->id.':moisture'), 'the role park is put on stage');
        $this->assertNull(Cache::get('namaa:sim:'.$adel->nodes()->first()->id.':moisture'), 'Sim 1 is a healthy park now');

        $this->assertSame($new->nodes()->first()->id, $scenarios->run('dry', $this->admin, $this->company->id)['node_id']);
        $this->assertSame($new->nodes()->first()->id, $scenarios->run('heat', $this->admin, $this->company->id)['node_id'], 'heat follows dry');

        $new->update(['demo_role' => null]);
        $this->assertSame($adel->nodes()->first()->id, $scenarios->run('dry', $this->admin, $this->company->id)['node_id'], 'no role: the config name');
    }

    public function test_reset_leaves_ndvi_drop_open(): void
    {
        $park = $this->simPark('Adel');
        $ndvi = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::NdviDrop]);
        $dry = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry]);

        app(ScenarioService::class)->run('reset', $this->admin, $this->company->id);

        $this->assertSame('open', $ndvi->refresh()->status->value);
        $this->assertSame('resolved', $dry->refresh()->status->value);
    }

    public function test_offline_scenario_uses_the_role_park(): void
    {
        $this->simPark('Adel', null, 'Sim 1');
        $this->simPark('Khat', null, 'Sim 2');
        $off = $this->simPark('Pick 4', 'offline', 'Sim 6');

        $r = app(ScenarioService::class)->run('offline', $this->admin, $this->company->id);

        $this->assertSame($off->id, $r['park_id']);
    }

    public function test_demo_role_moves_and_dry_run_changes_nothing(): void
    {
        $a = $this->simPark('Alpha', 'dry', 'Sim 1');
        $b = $this->simPark('Beta', null, 'Sim 2');

        $this->artisan('namaa:parks:demo-role', ['park' => 'Beta', 'step' => '1', '--dry-run' => true])
            ->expectsOutputToContain('from Alpha')->assertSuccessful();
        $this->assertSame('dry', $a->refresh()->demo_role);
        $this->assertNull($b->refresh()->demo_role);

        $this->artisan('namaa:parks:demo-role', ['park' => 'Beta', 'step' => 'dry'])->assertSuccessful();
        $this->assertNull($a->refresh()->demo_role);
        $this->assertSame('dry', $b->refresh()->demo_role);
        $this->assertTrue(ActivityLog::where('action', 'park.demo_role_changed')->where('subject_id', $b->id)->exists());

        $this->artisan('namaa:parks:demo-role', ['park' => 'Alpha', 'step' => '2'])->assertSuccessful();
        $this->assertSame('leak', $a->refresh()->demo_role);

        $this->artisan('namaa:parks:demo-role', ['park' => 'Alpha', 'step' => '3'])->expectsOutputToContain('litter')->assertSuccessful();
        $this->assertSame('leak', $a->refresh()->demo_role, 'step 3 has no role');
    }

    public function test_demo_role_needs_a_simulated_non_archived_park(): void
    {
        $sat = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'Sat', 'monitoring_mode' => MonitoringMode::SatelliteOnly]);
        $this->artisan('namaa:parks:demo-role', ['park' => 'Sat', 'step' => 'leak'])->assertFailed();
        $this->assertNull($sat->refresh()->demo_role);

        $arch = $this->simPark('Arch');
        $arch->update(['archived_at' => now()]);
        $this->artisan('namaa:parks:demo-role', ['park' => 'Arch', 'step' => 'leak'])->assertFailed();
    }

    public function test_archive_refuses_a_role_holder_and_detaches_otherwise(): void
    {
        $holder = $this->simPark('Holder', 'offline', 'Sim 1');
        $this->artisan('namaa:parks:archive', ['park' => 'Holder'])->assertFailed();
        $this->assertNull($holder->refresh()->archived_at);

        $plain = $this->simPark('Plain', null, 'Sim 2');
        $this->artisan('namaa:parks:archive', ['park' => 'Plain', '--dry-run' => true])->assertSuccessful();
        $this->assertNull($plain->refresh()->archived_at);

        $this->artisan('namaa:parks:archive', ['park' => 'Plain'])->assertSuccessful();
        $plain->refresh();
        $this->assertNotNull($plain->archived_at);
        $this->assertSame(MonitoringMode::SatelliteOnly, $plain->monitoring_mode);
        $this->assertNotNull($plain->nodes()->first()->detached_at);
    }

    public function test_stand_setup_dry_run_changes_nothing_and_move_keeps_tokens(): void
    {
        $bench = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'Workshop (test bench)', 'is_test' => true, 'site_type' => SiteType::Park]);
        $king = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'King Abdullah Park']);
        $model = Node::factory()->withPump()->create(['park_id' => $king->id, 'name' => 'Model A']);
        $cam = Node::factory()->camera()->create(['park_id' => $king->id, 'name' => 'Camera 1']);
        $token = $model->createToken('device', ['device'])->accessToken->token;
        $tokens = $model->tokens()->count();

        $this->artisan('namaa:stand:setup', ['--dry-run' => true, '--move-real-nodes' => true])->assertSuccessful();
        $this->assertSame($king->id, $model->refresh()->park_id);
        $this->assertSame(SiteType::Park, $bench->refresh()->site_type);

        $this->artisan('namaa:stand:setup', ['--move-real-nodes' => true])->assertSuccessful();

        $bench->refresh();
        $this->assertSame(SiteType::DemoStand, $bench->site_type);
        $this->assertSame('NAMAA demo stand', $bench->name_en);
        $this->assertTrue($bench->is_test);
        $this->assertSame($bench->id, $model->refresh()->park_id);
        $this->assertSame($bench->id, $cam->refresh()->park_id);
        $this->assertSame($tokens, $model->tokens()->count());
        $this->assertSame($token, $model->tokens()->first()->token);
        $this->assertSame(MonitoringMode::SatelliteOnly, $king->refresh()->monitoring_mode, 'the emptied park goes satellite only');

        $this->assertSame($bench->id, \App\Services\StandSetup::find($this->company->id)->id, 'found by site_type after the rename');
    }

    public function test_stand_setup_accepts_explicit_node_ids(): void
    {
        $bench = Park::factory()->demoStand()->create(['company_id' => $this->company->id]);
        $park = Park::factory()->create(['company_id' => $this->company->id]);
        $a = Node::factory()->create(['park_id' => $park->id, 'name' => 'Board X']);
        $b = Node::factory()->create(['park_id' => $park->id, 'name' => 'Board Y']);

        $this->artisan('namaa:stand:setup', ['--nodes' => (string) $a->id])->assertSuccessful();

        $this->assertSame($bench->id, $a->refresh()->park_id);
        $this->assertSame($park->id, $b->refresh()->park_id);
        $this->assertSame(MonitoringMode::RealSensors, $park->refresh()->monitoring_mode, 'a park that still has a node is not emptied');
    }

    public function test_story_endpoint(): void
    {
        $this->simPark('Adel', null, 'Sim 1');
        $this->simPark('Khat', null, 'Sim 2');
        $ju = $this->simPark('Juranah', 'dry', 'Sim 5');
        $sim = Node::factory()->camera()->simulated()->create(['park_id' => Park::where('name_en', 'Adel')->value('id'), 'name' => 'Sim Cam']);

        $data = $this->asUser($this->admin)->getJson('/api/admin/demo/story')->assertOk()->json('data');

        $this->assertSame($ju->id, $data['dry']['park_id']);
        $this->assertSame('Sim 5', $data['dry']['node']['name']);
        $this->assertSame('Khat', $data['leak']['name_en'], 'no role: the config node Sim 2');
        $this->assertNotNull($data['offline']);
        $this->assertSame($sim->id, $data['litter']['node']['id']);
        $this->assertSame('simulated', $data['litter']['node']['source']);

        // A live real camera on the stand wins.
        $stand = Park::factory()->demoStand()->create(['company_id' => $this->company->id]);
        $real = Node::factory()->camera()->create(['park_id' => $stand->id, 'name' => 'Camera 1', 'last_seen_at' => now()]);
        $data = $this->asUser($this->admin)->getJson('/api/admin/demo/story')->json('data');
        $this->assertSame($real->id, $data['litter']['node']['id']);
        $this->assertSame('real', $data['litter']['node']['source']);

        // An offline stand camera falls back.
        $real->update(['last_seen_at' => now()->subHour()]);
        $this->assertSame($sim->id, $this->asUser($this->admin)->getJson('/api/admin/demo/story')->json('data.litter.node.id'));
    }

    public function test_story_is_admin_only_and_company_scoped(): void
    {
        $this->simPark('Mine', 'dry');
        $this->simPark('Mine2', null, 'Sim 2');
        $other = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);

        $this->asUser($worker)->getJson('/api/admin/demo/story')->assertForbidden();
        $this->asUser($other)->getJson('/api/admin/demo/story')->assertOk()->assertJsonPath('data.dry', null);

        config(['namaa.demo_mode' => false]);
        $this->asUser($this->admin)->getJson('/api/admin/demo/story')->assertNotFound();
    }

    public function test_overview_has_the_demo_stand_block(): void
    {
        $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk()->assertJsonPath('data.demo_stand', null);

        $stand = Park::factory()->demoStand()->create(['company_id' => $this->company->id, 'name_en' => 'NAMAA demo stand']);
        Node::factory()->create(['park_id' => $stand->id, 'last_seen_at' => now(), 'last_moisture_pct' => 41.5]);

        $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk()
            ->assertJsonPath('data.demo_stand.park_id', $stand->id)
            ->assertJsonPath('data.demo_stand.online', true)
            ->assertJsonPath('data.demo_stand.latest.moisture_pct', 41.5);
    }

    public function test_a_real_stand_camera_is_still_polled_and_detached_ones_are_not(): void
    {
        $stand = Park::factory()->demoStand()->create(['company_id' => $this->company->id]);
        $cam = Node::factory()->camera()->create(['park_id' => $stand->id, 'camera_url' => 'http://cam.local', 'capture_mode' => 'url']);

        $this->assertSame([$cam->id], \App\Services\CameraPipeline::urlCameras()->pluck('id')->all());

        $cam->update(['detached_at' => now()]);
        $this->assertTrue(\App\Services\CameraPipeline::urlCameras()->isEmpty());
        $this->assertSame(NodeType::Camera, $cam->type);
    }
}
