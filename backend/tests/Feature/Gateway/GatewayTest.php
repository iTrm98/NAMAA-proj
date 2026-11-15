<?php

namespace Tests\Feature\Gateway;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\SiteType;
use App\Enums\TaskPriority;
use App\Jobs\ProcessGatewayEvent;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Camera\CameraTestHelpers;
use Tests\TestCase;

/** Sprint 6.3, BACKEND-1: Gateway ingest, zone nodes, alerts, commands, litter forward. */
class GatewayTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    private Node $zoneA;

    private Node $zoneB;

    private bool $gatewayUp = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
        config(['gateway.url' => 'http://gateway.test', 'gateway.ingest_token' => '', 'gateway.zone_b_camera_url' => 'http://camb.test']);
        Http::fake(['gateway.test/*' => fn () => $this->gatewayUp ? Http::response(['ok' => true]) : Http::failedConnection()]);

        $this->park->update(['site_type' => SiteType::DemoStand, 'is_test' => true, 'moisture_min' => 25, 'moisture_max' => 70]);
        $this->camera->update(['zone' => 'A', 'name' => 'Camera A']);
        $this->zoneA = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'name' => 'Zone A', 'zone' => 'A', 'last_seen_at' => null]);
        $this->zoneB = Node::factory()->create(['park_id' => $this->park->id, 'name' => 'Zone B', 'zone' => 'B', 'last_seen_at' => null]);
    }

    /** @return array<string, mixed> */
    private function telemetry(array $a = [], array $b = [], array $extra = []): array
    {
        return ['event' => 'telemetry', 'park' => 'demo-stand', 'data' => $extra + [
            'auto' => true, 'irrigatingZone' => null, 'pulses' => 0, 'lastCycleMl' => 120,
            'zones' => [
                'A' => $a + ['online' => true, 'ageS' => 1, 'soilPct' => 41.0, 'tempC' => 29.5, 'humidity' => 33, 'flowMlMin' => 420, 'totalMl' => 1000, 'pumpOn' => false, 'flags' => 0],
                'B' => $b + ['online' => true, 'ageS' => 0, 'soilPct' => 55.0, 'tempC' => 30.0, 'humidity' => 30, 'flowMlMin' => 0, 'totalMl' => 0, 'pumpOn' => false, 'flags' => 0],
            ],
            'active' => [],
        ]];
    }

    private function send(array $body)
    {
        return $this->postJson('/api/namaa/ingest', $body);
    }

    public function test_telemetry_becomes_readings_with_flow_in_litres(): void
    {
        $this->send($this->telemetry())->assertOk()->assertExactJson(['ok' => true]);

        $r = Reading::where('node_id', $this->zoneA->id)->sole();
        $this->assertSame(0.42, $r->flow);
        $this->assertSame(41.0, $r->moisture_pct);
        $this->assertNull($r->moisture_raw);
        $this->assertSame(1, Reading::where('node_id', $this->zoneB->id)->count());
        $this->assertSame(0.42, $this->zoneA->fresh()->last_flow);
        $this->assertNotNull($this->zoneA->fresh()->last_seen_at);
    }

    public function test_age_moves_last_seen_back_and_an_offline_zone_does_not_update(): void
    {
        $this->send($this->telemetry(['ageS' => 10], ['online' => false, 'ageS' => 99]))->assertOk();

        $this->assertEqualsWithDelta(10, $this->zoneA->fresh()->last_seen_at->diffInSeconds(now(), true), 2);
        $this->assertNull($this->zoneB->fresh()->last_seen_at);
        $this->assertSame(0, Reading::where('node_id', $this->zoneB->id)->count());
    }

    public function test_at_most_one_reading_per_zone_every_two_seconds(): void
    {
        $this->send($this->telemetry());
        $this->send($this->telemetry());
        $this->assertSame(1, Reading::where('node_id', $this->zoneA->id)->count());

        $this->travel(3)->seconds();
        $this->send($this->telemetry());
        $this->assertSame(2, Reading::where('node_id', $this->zoneA->id)->count());
    }

    public function test_the_reply_is_instant_and_the_work_is_queued_on_the_gateway_queue(): void
    {
        Queue::fake();

        $this->send($this->telemetry())->assertOk()->assertExactJson(['ok' => true]);

        Queue::assertPushedOn('gateway', ProcessGatewayEvent::class);
        $this->assertSame(0, Reading::count());
    }

    public function test_the_ingest_token_is_optional_but_enforced_when_set(): void
    {
        $this->send($this->telemetry())->assertOk();

        config(['gateway.ingest_token' => 'secret-token']);
        $this->send($this->telemetry())->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/namaa/ingest', $this->telemetry())->assertUnauthorized();
        $this->withToken('secret-token')->postJson('/api/namaa/ingest', $this->telemetry())->assertOk();
    }

    public function test_a_bad_token_is_401_before_validation_422(): void
    {
        config(['gateway.ingest_token' => 'secret-token']);
        $this->send(['event' => 'nope'])->assertUnauthorized();
    }

    public function test_pump_service_commands_the_gateway_for_zone_nodes_and_never_writes_pump_target(): void
    {
        $user = User::factory()->create(['company_id' => $this->park->company_id]);
        $svc = app(\App\Services\PumpService::class);

        $svc->set($this->zoneA, true, \App\Enums\PumpReason::Manual, $user);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/cmd') && $r['zone'] === 'A' && $r['action'] === 'on');
        $this->assertFalse((bool) $this->zoneA->fresh()->pump_target);

        $this->gatewayUp = false;
        $this->expectException(\App\Services\GatewayUnreachable::class);
        $svc->set($this->zoneA, false, \App\Enums\PumpReason::Manual, $user);
    }

    public function test_water_today_keeps_the_delta_across_the_makkah_day_rollover(): void
    {
        $state = app(\App\Services\GatewayState::class);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 20:59:00', 'UTC')); // 23:59 Riyadh
        $state->addTotal('A', 1000);
        $state->addTotal('A', 1500);
        $this->assertSame(500, $state->waterTodayMl('A'));

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-14 21:01:00', 'UTC')); // 00:01 next day
        $this->assertSame(0, $state->waterTodayMl('A'));
        $state->addTotal('A', 1800);
        $this->assertSame(300, $state->waterTodayMl('A'));
    }

    public function test_ingest_validates_the_body(): void
    {
        $this->send(['event' => 'nope', 'data' => []])->assertStatus(422);
        $this->send(['event' => 'telemetry'])->assertStatus(422);
    }

    public function test_no_rules_engine_runs_for_zone_nodes(): void
    {
        // 5 % is far below the park minimum (25): the server's dry rule would fire for a normal node.
        $this->send($this->telemetry(['soilPct' => 5.0, 'pumpOn' => true]))->assertOk();

        $this->assertSame(0, Alert::count());
        $this->assertTrue($this->zoneA->fresh()->pump_target);
        $this->assertSame(0, \App\Models\PumpLog::count());
    }

    public function test_active_list_raises_missing_alerts_and_clears_absent_ones(): void
    {
        $this->send($this->telemetry(extra: ['active' => [['zone' => 'A', 'type' => 'dry'], ['zone' => 'B', 'type' => 'tank_low']]]))->assertOk();

        $this->assertSame(2, Alert::open()->count());
        $dry = Alert::where('type', AlertType::Dry)->sole();
        $this->assertSame('gateway', $dry->data['origin']);
        $this->assertSame('A', $dry->data['zone']);
        $this->assertSame($this->zoneA->id, $dry->node_id);

        $this->send($this->telemetry(extra: ['active' => [['zone' => 'B', 'type' => 'tank_low']]]))->assertOk();

        $this->assertSame(AlertStatus::Resolved, $dry->fresh()->status);
        $this->assertSame(1, Alert::open()->count());
    }

    public function test_alert_events_raise_and_clear(): void
    {
        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'leak', 'state' => 'raised', 'flowMlMin' => 900]])->assertOk();
        $alert = Alert::sole();
        $this->assertSame(AlertType::Leak, $alert->type);
        $this->assertSame(900, $alert->data['flowMlMin']);

        // A repeat does not duplicate it.
        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'leak', 'state' => 'raised']])->assertOk();
        $this->assertSame(1, Alert::count());

        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'leak', 'state' => 'cleared']])->assertOk();
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_unknown_alert_types_are_ignored(): void
    {
        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'weird', 'state' => 'raised']])->assertOk();
        $this->assertSame(0, Alert::count());
    }

    public function test_tank_low_and_heat_types(): void
    {
        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'tank_low', 'state' => 'raised']]);
        $this->send(['event' => 'alert', 'data' => ['zone' => 'B', 'type' => 'heat', 'state' => 'raised']]);

        $tank = Alert::where('type', AlertType::TankLow)->sole();
        $heat = Alert::where('type', AlertType::Heat)->sole();
        $this->assertSame(TaskPriority::Urgent, $tank->priority);
        $this->assertSame(TaskPriority::Normal, $heat->priority);
        $this->assertSame('water', $tank->type->category()->value);
        $this->assertSame('plant', $heat->type->category()->value);
    }

    public function test_a_clear_with_an_active_task_only_stamps_and_a_new_raise_removes_the_stamp(): void
    {
        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'dry', 'state' => 'raised']]);
        $alert = Alert::sole();
        Task::factory()->inProgress()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id]);

        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'dry', 'state' => 'cleared']]);
        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
        $this->assertNotEmpty($alert->fresh()->data['gateway_cleared_at']);

        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'dry', 'state' => 'raised']]);
        $this->assertArrayNotHasKey('gateway_cleared_at', $alert->fresh()->data);
    }

    public function test_a_done_gateway_task_is_verified_after_the_window_once_the_gateway_cleared(): void
    {
        config(['namaa.demo_mode' => true]); // 60 s window
        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'dry', 'state' => 'raised']]);
        $alert = Alert::sole();
        $task = Task::factory()->done()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id, 'done_at' => now()]);

        $verifier = app(TaskVerifier::class);
        $this->assertNull($verifier->verify($task)); // not cleared yet

        $this->send(['event' => 'alert', 'data' => ['zone' => 'A', 'type' => 'dry', 'state' => 'cleared']]);
        $this->assertNull($verifier->verify($task->fresh())); // cleared, window not reached

        $this->travel(61)->seconds();
        $this->assertTrue($verifier->verify($task->fresh()));
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_pump_and_offline_safety_skip_zone_nodes(): void
    {
        $this->zoneA->update(['pump_target' => true]);
        $this->assertSame(0, app(\App\Services\PumpService::class)->standWatchdog());
        $this->assertSame(0, app(\App\Services\PumpService::class)->cutOffLongRuns());
        $this->assertTrue($this->zoneA->fresh()->pump_target);

        app(\App\Services\OfflineChecker::class)->run();
        $this->assertSame(0, Alert::count());
    }

    // ---- commands ----

    public function test_the_pump_command_goes_to_the_gateway(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['zone' => 'A', 'on' => true, 'seconds' => 12])
            ->assertOk()->assertJsonPath('data.mode', 'gateway');

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://gateway.test/api/cmd')
            && $r['zone'] === 'A' && $r['action'] === 'on' && (int) $r['sec'] === 12);

        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['zone' => 'B', 'on' => false])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'action=off') && ! str_contains($r->url(), 'sec='));
    }

    public function test_the_auto_command(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/stand/auto', ['on' => false])->assertOk();
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://gateway.test/api/auto') && (int) $r['on'] === 0);
    }

    public function test_commands_validate_and_report_an_unreachable_gateway(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['zone' => 'C', 'on' => true])->assertStatus(422);
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['zone' => 'A', 'on' => true, 'seconds' => 99])->assertStatus(422);
        $this->asUser($this->admin)->postJson('/api/admin/stand/auto', [])->assertStatus(422);

        $this->gatewayUp = false;
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['zone' => 'A', 'on' => true])
            ->assertStatus(502)->assertExactJson(['error' => 'gateway_unreachable']);
        $this->asUser($this->admin)->postJson('/api/admin/stand/auto', ['on' => true])->assertStatus(502);
    }

    public function test_stand_endpoints_are_admin_only_and_company_scoped(): void
    {
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->asUser($worker)->postJson('/api/admin/stand/pump', ['zone' => 'A', 'on' => true])->assertForbidden();
        $this->asUser($worker)->postJson('/api/admin/stand/auto', ['on' => true])->assertForbidden();

        $other = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($other)->postJson('/api/admin/stand/pump', ['zone' => 'A', 'on' => true])->assertNotFound();
        $this->asUser($other)->postJson('/api/admin/stand/auto', ['on' => true])->assertNotFound();
        $this->asUser($other)->getJson('/api/admin/stand/live')->assertNotFound();
    }

    public function test_live_payload_in_gateway_mode(): void
    {
        $this->send($this->telemetry(extra: ['active' => [['zone' => 'A', 'type' => 'dry']]]));
        $this->travel(3)->seconds();
        $this->send($this->telemetry(['totalMl' => 1500], extra: ['active' => [['zone' => 'A', 'type' => 'dry']]]));

        $live = $this->asUser($this->admin)->getJson('/api/admin/stand/live')->assertOk()
            ->assertJsonPath('data.mode', 'gateway')
            ->assertJsonPath('data.gateway.reachable', true)
            ->assertJsonPath('data.gateway.auto', true)
            ->assertJsonPath('data.gateway.last_cycle_ml', 120)
            ->assertJsonPath('data.zones.0.zone', 'A')
            ->assertJsonPath('data.zones.0.online', true)
            ->assertJsonPath('data.zones.0.flow_lpm', 0.42)
            ->assertJsonPath('data.zones.0.water_today_l', 0.5)
            ->assertJsonPath('data.zones.0.alerts.0.type', 'dry')
            ->assertJsonPath('data.zones.0.camera.node_id', $this->camera->id)
            ->assertJsonPath('data.zones.1.zone', 'B')
            ->assertJsonPath('data.bench', null);
        $this->assertContains('dry', $live->json('data.zones.0.flags'));
        $this->assertSame('alert_raised', $live->json('data.timeline.0.kind'));

        // 30 s of silence: the zone is offline.
        $this->travel(31)->seconds();
        $this->asUser($this->admin)->getJson('/api/admin/stand/live')
            ->assertJsonPath('data.zones.0.online', false)->assertJsonPath('data.gateway.reachable', false);
    }

    public function test_flag_bits_become_plain_statuses(): void
    {
        $this->assertSame(['tank_low', 'leak', 'pump_locked'], \App\Services\GatewayIngestor::flagNames(1 | 4 | 64));
        $this->send($this->telemetry(['flags' => 2 | 8]));
        $flags = $this->asUser($this->admin)->getJson('/api/admin/stand/live')->json('data.zones.0.flags');
        $this->assertSame(['no_flow', 'soil_fault'], $flags);
    }

    public function test_readiness_has_the_gateway_rows_and_the_stand_test(): void
    {
        $data = $this->asUser($this->admin)->getJson('/api/admin/stand/readiness')->assertOk()->json('data');
        $this->assertSame(
            ['gateway', 'zone_a', 'zone_b', 'camera_a', 'camera_b', 'vision', 'litter_forward', 'queue', 'scheduler', 'ingest_token', 'mode', 'tray', 'ndvi'],
            array_column($data['checks'], 'key'),
        );
        $this->assertFalse(collect($data['checks'])->firstWhere('key', 'ndvi')['required']);
        $this->assertTrue(collect($data['checks'])->firstWhere('key', 'pump_a') === null);

        $this->asUser($this->admin)->postJson('/api/admin/stand/test', ['key' => 'pump_a'])
            ->assertOk()->assertJsonPath('data.ok', true);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/cmd') && (int) $r['sec'] === 3);
        $this->asUser($this->admin)->postJson('/api/admin/stand/test', ['key' => 'bogus'])->assertStatus(422);

        $this->gatewayUp = false;
        $this->asUser($this->admin)->postJson('/api/admin/stand/test', ['key' => 'litter_b'])
            ->assertOk()->assertJsonPath('data.ok', false);
    }

    // ---- litter forward ----

    public function test_a_camera_frame_is_forwarded_to_the_gateway_without_a_server_alert(): void
    {
        $this->litterQueue = [2];
        $this->artisan('namaa:capture')->assertSuccessful();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://gateway.test/api/litter')
            && $r['zone'] === 'A' && (int) $r['count'] === 2 && $r['source'] === 'NAMAA' && ! isset($r['clear']) && (float) $r['conf'] === 0.8);
        $this->assertSame(1, Detection::count());
        $this->assertSame(0, Alert::count()); // the Gateway raises litter, not the server
    }

    public function test_a_clean_frame_forwards_clear_and_a_failed_forward_never_breaks_the_pipeline(): void
    {
        $this->litterQueue = [0];
        $this->artisan('namaa:capture')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/litter') && (int) $r['clear'] === 1 && (int) $r['count'] === 0);

        $this->gatewayUp = false;
        $this->travel(\App\Support\Namaa::timing('camera_capture_seconds'))->seconds();
        $this->litterQueue = [1];
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->assertSame(2, Detection::count());
    }

    public function test_zone_b_camera_uses_its_own_url_and_never_the_zone_a_one(): void
    {
        $b = Node::factory()->camera()->create(['park_id' => $this->park->id, 'name' => 'Camera B', 'zone' => 'B', 'camera_url' => null]);
        $this->assertSame('http://camb.test', \App\Services\CameraPipeline::urlFor($b));
        $this->assertSame('http://camera.test', \App\Services\CameraPipeline::urlFor($this->camera));
    }

    // ---- hardware list and cleanup ----

    public function test_the_nodes_list_hides_placeholders_unless_asked(): void
    {
        $ghost = Node::factory()->create(['park_id' => $this->park->id, 'name' => 'Model A']);

        $ids = collect($this->asUser($this->admin)->getJson('/api/admin/nodes?include_test=1')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($ghost->id));
        $this->assertTrue($ids->contains($this->zoneA->id));

        $ids = collect($this->asUser($this->admin)->getJson('/api/admin/nodes?include_test=1&placeholders=1')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ghost->id));
    }

    public function test_node_cleanup_dry_run_changes_nothing_and_a_real_run_detaches(): void
    {
        $ghost = Node::factory()->create(['park_id' => $this->park->id, 'name' => 'Bench B']);

        $this->artisan('namaa:node-cleanup --dry-run')->assertSuccessful();
        $this->assertNull($ghost->fresh()->detached_at);

        $this->artisan('namaa:node-cleanup')->assertSuccessful();
        $this->assertNotNull($ghost->fresh()->detached_at);
        $this->assertNull($this->zoneA->fresh()->detached_at);
    }
}
