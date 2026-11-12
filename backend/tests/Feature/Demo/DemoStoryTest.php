<?php

namespace Tests\Feature\Demo;

use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Enums\PumpReason;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\Task;
use App\Models\User;
use App\Services\CameraFeed;
use App\Services\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Phase C: the demo reset tells the story; a repair ends the simulated fault; fields the UI needs. */
class DemoStoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $ahmed;

    private Park $adel;

    private Node $adelNode;

    private Park $khat;

    private Node $khatNode;

    private string $samples;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.demo_mode' => true]);

        $this->samples = sys_get_temp_dir().'/namaa-story-'.uniqid();
        foreach (['litter', 'clean'] as $kind) {
            File::ensureDirectoryExists("{$this->samples}/{$kind}");
            File::put("{$this->samples}/{$kind}/01.jpg", "bytes-{$kind}");
        }
        config(['namaa.camera.samples_path' => $this->samples]);

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->ahmed = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->adel = Park::factory()->simulated()->create(['company_id' => $this->company->id, 'moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Ok]);
        $this->adelNode = Node::factory()->simulated()->create(['park_id' => $this->adel->id, 'name' => 'Sim 1', 'last_moisture_pct' => 50]); // no pump yet
        $this->khat = Park::factory()->simulated()->create(['company_id' => $this->company->id, 'moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Ok]);
        $this->khatNode = Node::factory()->simulated()->withPump()->create(['park_id' => $this->khat->id, 'name' => 'Sim 2', 'last_moisture_pct' => 50]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->samples);
        parent::tearDown();
    }

    private function doneTask(Alert $alert): void
    {
        $task = Task::factory()->inProgress()->create(['park_id' => $alert->park_id, 'alert_id' => $alert->id, 'assigned_to' => $this->ahmed->id, 'company_id' => $this->company->id]);
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$task->id}/done")->assertOk();
        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
    }

    public function test_reset_prepares_the_story_al_adel_dry_with_a_pump_and_automatic_irrigation(): void
    {
        $this->assertFalse($this->adelNode->has_pump);
        $khatAuto = (bool) $this->khat->fresh()->auto_irrigation;

        app(ScenarioService::class)->run('reset', $this->admin, $this->company->id);

        $this->adelNode->refresh();
        $this->assertTrue($this->adelNode->has_pump, 'a pump is added to the demo park');
        $this->assertEquals(2.0, $this->adelNode->expected_flow_l_min);
        $this->assertTrue($this->adel->refresh()->auto_irrigation);
        $this->assertEquals(35.0, Cache::get("namaa:sim:{$this->adelNode->id}:moisture"));
        $this->assertTrue($this->khatNode->refresh()->has_pump);
        $this->assertSame($khatAuto, (bool) $this->khat->refresh()->auto_irrigation, 'healthy park setting kept');
    }

    public function test_marking_a_leak_task_done_ends_the_simulated_leak(): void
    {
        app(ScenarioService::class)->run('leak', $this->admin, $this->company->id);
        $this->assertTrue(app(ScenarioService::class)->isLeaking($this->khatNode));
        $alert = Alert::factory()->create(['park_id' => $this->khat->id, 'node_id' => $this->khatNode->id, 'type' => AlertType::Leak]);

        $this->doneTask($alert);

        $this->assertFalse(app(ScenarioService::class)->isLeaking($this->khatNode));
    }

    public function test_marking_a_blockage_task_done_ends_the_simulated_blockage(): void
    {
        Cache::forever(ScenarioService::cacheKey($this->khatNode->id, 'blockage'), true);
        $alert = Alert::factory()->create(['park_id' => $this->khat->id, 'node_id' => $this->khatNode->id, 'type' => AlertType::Blockage]);

        $this->doneTask($alert);

        $this->assertFalse(app(ScenarioService::class)->isBlocked($this->khatNode));
    }

    public function test_a_real_node_fault_is_never_touched_by_a_repair(): void
    {
        $real = Node::factory()->withPump()->create(['park_id' => Park::factory()->create(['company_id' => $this->company->id])->id]);
        Cache::forever(ScenarioService::cacheKey($real->id, 'leak'), true);
        $alert = Alert::factory()->create(['park_id' => $real->park_id, 'node_id' => $real->id, 'type' => AlertType::Leak]);

        $this->doneTask($alert);

        $this->assertTrue(app(ScenarioService::class)->isLeaking($real));
    }

    public function test_marking_a_litter_task_done_makes_the_demo_camera_send_clean_frames_and_the_scenario_restarts_litter(): void
    {
        $cam = Node::factory()->camera()->simulated()->create(['park_id' => $this->adel->id, 'name' => 'Sim Cam']);
        app(ScenarioService::class)->run('litter', $this->admin, $this->company->id);
        $this->assertSame('litter', app(CameraFeed::class)->current()['kind']);

        $detection = Detection::factory()->create(['park_id' => $this->adel->id, 'node_id' => $cam->id]);
        $alert = Alert::factory()->create(['park_id' => $this->adel->id, 'node_id' => $cam->id, 'type' => AlertType::Litter, 'detection_id' => $detection->id]);
        $this->doneTask($alert);

        $feed = app(CameraFeed::class)->current();
        $this->assertSame('clean', $feed['kind']);
        $this->assertSame($cam->id, $feed['node_id']);
        $this->travel(20)->seconds();
        $this->assertSame('bytes-clean', app(CameraFeed::class)->next(10)['bytes']);

        app(ScenarioService::class)->run('litter', $this->admin, $this->company->id);
        $this->assertSame('litter', app(CameraFeed::class)->current()['kind']);
    }

    public function test_marking_a_litter_task_done_sends_the_first_clean_frame_at_once(): void
    {
        $cam = Node::factory()->camera()->simulated()->create(['park_id' => $this->adel->id, 'name' => 'Sim Cam']);
        $pipeline = $this->mock(\App\Services\CameraPipeline::class);
        $pipeline->shouldReceive('process')->once()
            ->withArgs(fn ($node, $bytes, $origin) => $node->id === $cam->id && $bytes === 'bytes-clean' && $origin === 'scenario');

        $this->doneTask(Alert::factory()->create(['park_id' => $this->adel->id, 'node_id' => $cam->id, 'type' => AlertType::Litter]));

        $this->assertNull(app(CameraFeed::class)->next(10), 'the next frame waits for the capture interval');
        $this->travel(10)->seconds();
        $this->assertSame('bytes-clean', app(CameraFeed::class)->next(10)['bytes']);
    }

    public function test_the_litter_repair_needs_demo_mode_and_a_simulated_camera(): void
    {
        $cam = Node::factory()->camera()->simulated()->create(['park_id' => $this->adel->id, 'name' => 'Sim Cam']);
        $alert = Alert::factory()->create(['park_id' => $this->adel->id, 'node_id' => $cam->id, 'type' => AlertType::Litter]);
        config(['namaa.demo_mode' => false]);
        $this->doneTask($alert);
        $this->assertNull(app(CameraFeed::class)->current());

        config(['namaa.demo_mode' => true]);
        $real = Node::factory()->camera()->create(['park_id' => Park::factory()->create(['company_id' => $this->company->id])->id]);
        $this->doneTask(Alert::factory()->create(['park_id' => $real->park_id, 'node_id' => $real->id, 'type' => AlertType::Litter]));
        $this->assertNull(app(CameraFeed::class)->current(), 'a real camera only gets frames from the capture job');
    }

    public function test_the_park_says_when_automatic_irrigation_is_watering(): void
    {
        $this->khat->update(['auto_irrigation' => true]);
        $this->khatNode->update(['pump_target' => true]);
        PumpLog::create(['node_id' => $this->khatNode->id, 'target' => true, 'reason' => PumpReason::Rule, 'created_at' => now()]);

        $list = $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()->json('data');
        $byId = collect($list)->keyBy('id');
        $this->assertTrue($byId[$this->khat->id]['auto_irrigation_active']);
        $this->assertFalse($byId[$this->adel->id]['auto_irrigation_active']);

        // switched on by a person: running, but not automatic
        PumpLog::create(['node_id' => $this->khatNode->id, 'target' => true, 'reason' => PumpReason::Manual, 'created_at' => now()->addSecond()]);
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->khat->id}")->assertJsonPath('data.auto_irrigation_active', false);
    }

    public function test_the_park_list_does_not_query_the_pump_log_per_park(): void
    {
        foreach (range(1, 4) as $i) {
            $park = Park::factory()->simulated()->create(['company_id' => $this->company->id, 'auto_irrigation' => true]);
            Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'pump_target' => true]);
        }
        DB::enableQueryLog();
        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk();
        $pumpLogQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'pump_logs'))->count();
        $this->assertLessThanOrEqual(1, $pumpLogQueries);
    }

    public function test_the_overview_has_uncapped_needs_attention_counts(): void
    {
        Alert::factory()->count(12)->create(['park_id' => $this->khat->id, 'type' => AlertType::Dry]);
        Task::factory()->inProgress()->count(2)->create(['park_id' => $this->khat->id, 'company_id' => $this->company->id]);

        $r = $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk();
        $r->assertJsonPath('data.needs_attention.counts.alerts', 12)
            ->assertJsonPath('data.needs_attention.counts.tasks_in_progress', 2)
            ->assertJsonPath('data.needs_attention.counts.setup', count($r->json('data.needs_attention.setup')));
        $this->assertCount(10, $r->json('data.needs_attention.alerts'));
    }

    public function test_the_worker_task_carries_the_category_alert_data_frame_and_node(): void
    {
        $cam = Node::factory()->camera()->simulated()->create(['park_id' => $this->adel->id, 'name' => 'Sim Cam', 'last_seen_at' => now()]);
        $detection = Detection::factory()->create(['park_id' => $this->adel->id, 'node_id' => $cam->id]);
        $alert = Alert::factory()->create(['park_id' => $this->adel->id, 'node_id' => $cam->id, 'type' => AlertType::Litter, 'detection_id' => $detection->id]);
        $task = Task::factory()->create(['park_id' => $this->adel->id, 'alert_id' => $alert->id, 'assigned_to' => $this->ahmed->id, 'company_id' => $this->company->id]);

        $this->asUser($this->ahmed)->getJson("/api/worker/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.category', 'cleanliness')
            ->assertJsonPath('data.alert.frame_url', "/worker/tasks/{$task->id}/frame")
            ->assertJsonPath('data.alert.detection.id', $detection->id)
            ->assertJsonPath('data.alert.node.name', 'Sim Cam')
            ->assertJsonPath('data.alert.node.source', 'simulated');
    }
}
