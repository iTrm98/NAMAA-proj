<?php

namespace Tests\Feature\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Enums\PumpReason;
use App\Enums\TaskPriority;
use App\Events\AlertRaised;
use App\Events\AlertResolved;
use App\Events\ParkStatusChanged;
use App\Events\ReadingReceived;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Services\ParkStatusService;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RulesEngineTest extends TestCase
{
    use RefreshDatabase;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->park = Park::factory()->create(['moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Offline]);
        $this->node = Node::factory()->withPump()->create([
            'park_id' => $this->park->id, 'dry_value' => 3200, 'wet_value' => 1300, 'last_seen_at' => null,
        ]);
    }

    /** Raw value for a moisture % with the default calibration (3200 dry, 1300 wet). */
    private function raw(float $pct): int
    {
        return (int) round(3200 - $pct / 100 * 1900);
    }

    private function send(?float $pct, array $extra = [], ?Node $node = null): void
    {
        $node ??= $this->node;
        $this->travel(5)->seconds();
        app(ReadingIngestor::class)->ingest($node->fresh(), ['moisture' => $pct === null ? null : $this->raw($pct)] + $extra);
    }

    private function open(AlertType $type): ?Alert
    {
        return Alert::open()->where('type', $type)->first();
    }

    // ---- dry ----

    public function test_dry_raises_at_the_second_reading_not_before(): void
    {
        Event::fake([AlertRaised::class, ParkStatusChanged::class, ReadingReceived::class]);

        $this->send(20);
        $this->assertNull($this->open(AlertType::Dry));
        $this->assertSame(ParkStatus::Ok, $this->park->refresh()->status);

        $this->send(19, ['temperature' => 33.0]);
        $alert = $this->open(AlertType::Dry);
        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(TaskPriority::High, $alert->priority);
        $this->assertNotNull($alert->task_ready_at);
        $this->assertSame($this->node->id, $alert->node_id);
        $this->assertEquals(['moisture_pct' => 19.0, 'moisture_min' => 25, 'temperature' => 33.0, 'auto_irrigation' => false, 'readings' => 2], $alert->data);
        $this->assertSame(ParkStatus::Dry, $this->park->refresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.raised', 'subject_id' => $alert->id, 'company_id' => $this->park->company_id]);

        Event::assertDispatched(AlertRaised::class, fn ($e) => $e->payload['alert']['type'] === 'dry'
            && $e->payload['alert']['priority'] === 'high' && $e->payload['alert']['category'] === 'plant'
            && $e->broadcastAs() === 'alert.raised');
        Event::assertDispatched(ParkStatusChanged::class, fn ($e) => $e->payload['status'] === 'dry');
        Event::assertDispatchedTimes(ReadingReceived::class, 2);
    }

    public function test_dry_just_at_the_minimum_does_not_raise(): void
    {
        $this->send(25);
        $this->send(25);
        $this->send(25);

        $this->assertNull($this->open(AlertType::Dry));
    }

    public function test_a_repeat_updates_the_open_alert_instead_of_a_new_one(): void
    {
        foreach ([20, 20, 20] as $pct) {
            $this->send($pct);
        }
        $alert = $this->open(AlertType::Dry);
        $createdAt = $alert->created_at;

        $this->send(15);

        $this->assertSame(1, Alert::where('type', AlertType::Dry)->count());
        $alert->refresh();
        $this->assertEquals(15.0, $alert->data['moisture_pct']);
        $this->assertSame(4, $alert->data['readings']);
        $this->assertEquals($createdAt, $alert->created_at);
    }

    public function test_dry_resolves_after_two_readings_at_or_above_the_minimum_plus_margin(): void
    {
        Event::fake([AlertResolved::class]);
        foreach ([20, 20, 20] as $pct) {
            $this->send($pct);
        }

        $this->send(30);
        $this->assertNotNull($this->open(AlertType::Dry), 'one good reading is not enough');
        $this->assertSame(ParkStatus::Dry, $this->park->refresh()->status);

        $this->send(30); // min 25 + margin 5
        $alert = Alert::where('type', AlertType::Dry)->sole();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame(ParkStatus::Ok, $this->park->refresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.resolved', 'subject_id' => $alert->id]);
        Event::assertDispatched(AlertResolved::class, fn ($e) => $e->payload['alert_id'] === $alert->id);
    }

    // ---- over ----

    public function test_over_raises_updates_and_resolves(): void
    {
        $on = ['pump_on' => true, 'flow' => 1.8];
        $this->send(80, $on);
        $this->send(80, $on);
        $this->assertNull($this->open(AlertType::Over));
        $this->send(85, $on);

        $alert = $this->open(AlertType::Over);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertSame(TaskPriority::Normal, $alert->priority);
        $this->assertSame(ParkStatus::Over, $this->park->refresh()->status);

        $this->send(90, $on);
        $this->assertSame(1, Alert::where('type', AlertType::Over)->count());
        $this->assertSame(4, $alert->refresh()->data['readings']);

        $this->send(70);
        $this->send(60);
        $this->assertSame(AlertStatus::Open, $alert->refresh()->status, '70 is at the max, not max - margin');
        $this->send(65);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
        $this->assertSame(ParkStatus::Ok, $this->park->refresh()->status);
    }

    // ---- no_flow ----

    public function test_no_flow_raises_switches_the_pump_off_and_resolves(): void
    {
        $this->node->update(['pump_target' => true]);

        $this->send(50, ['pump_on' => true, 'flow' => 0.0]);
        $this->send(50, ['pump_on' => true, 'flow' => 0.05]); // below the 0.1 L/min noise floor
        $this->assertNull($this->open(AlertType::NoFlow));
        $this->send(50, ['pump_on' => true, 'flow' => 0.0]);

        $alert = $this->open(AlertType::NoFlow);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame('urgent', AlertType::NoFlow->taskPriority()->value);
        $this->assertFalse($this->node->refresh()->pump_target);
        $log = PumpLog::sole();
        $this->assertFalse($log->target);
        $this->assertSame(PumpReason::Safety, $log->reason);
        $this->assertNull($log->user_id);

        $this->send(50, ['pump_on' => true, 'flow' => 0.0]);
        $this->assertSame(1, Alert::where('type', AlertType::NoFlow)->count());
        $this->assertSame(4, $alert->refresh()->data['readings']);

        $this->send(50, ['pump_on' => false, 'flow' => 0.0]); // the board applied "off"
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
        $this->assertNull($this->open(AlertType::Leak));
    }

    // ---- leak ----

    public function test_leak_raises_updates_and_resolves(): void
    {
        $this->send(50, ['pump_on' => false, 'flow' => 0.8]);
        $this->send(50, ['pump_on' => false, 'flow' => 0.7]);
        $this->assertNull($this->open(AlertType::Leak));
        $this->send(50, ['pump_on' => false, 'flow' => 0.9]);

        $alert = $this->open(AlertType::Leak);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame('urgent', AlertType::Leak->taskPriority()->value);

        $this->send(50, ['pump_on' => false, 'flow' => 0.6]);
        $this->assertSame(1, Alert::where('type', AlertType::Leak)->count());
        $this->assertEquals(0.6, $alert->refresh()->data['flow']);

        $this->send(50, ['pump_on' => false, 'flow' => 0.0]);
        $this->assertNotNull($this->open(AlertType::Leak));
        $this->send(50, ['pump_on' => false, 'flow' => 0.0]);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_flow_rules_skip_nodes_without_a_pump_or_flow(): void
    {
        $this->node->update(['has_pump' => false]);
        foreach ([1, 2, 3] as $i) {
            $this->send(50, ['pump_on' => false, 'flow' => 0.9]);
        }
        $this->node->update(['has_pump' => true]);
        foreach ([1, 2, 3] as $i) {
            $this->send(50, ['pump_on' => true, 'flow' => null]);
        }

        $this->assertSame(0, Alert::whereIn('type', [AlertType::Leak, AlertType::NoFlow])->count());
    }

    // ---- sensor_fault ----

    public function test_sensor_fault_raises_and_suppresses_dry(): void
    {
        $this->travel(5)->seconds();
        $ingest = app(ReadingIngestor::class);
        foreach ([4095, 4092, 4095] as $raw) { // 0 % would look dry
            $this->travel(5)->seconds();
            $ingest->ingest($this->node->fresh(), ['moisture' => $raw]);
        }

        $fault = $this->open(AlertType::SensorFault);
        $this->assertNotNull($fault);
        $this->assertSame(AlertSeverity::Warning, $fault->severity);
        $this->assertNull($this->open(AlertType::Dry), 'a broken sensor must not raise dry');

        // While the fault is open, dry readings do not raise dry either.
        $this->send(null);
        $this->assertNull($this->open(AlertType::Dry));
        $this->assertSame(4, $fault->refresh()->data['readings']);
    }

    public function test_sensor_fault_counts_low_raw_and_null_and_resolves_after_two_normal_values(): void
    {
        $ingest = app(ReadingIngestor::class);
        foreach ([10, null, 50] as $raw) {
            $this->travel(5)->seconds();
            $ingest->ingest($this->node->fresh(), ['moisture' => $raw]);
        }
        $fault = $this->open(AlertType::SensorFault);
        $this->assertNotNull($fault);

        $this->send(20);
        $this->assertNotNull($this->open(AlertType::SensorFault));
        $this->send(21);
        $this->assertSame(AlertStatus::Resolved, $fault->refresh()->status);

        // Two valid dry readings: the fault resolves first, then dry is raised by the same reading.
        $this->assertNotNull($this->open(AlertType::Dry));
    }

    public function test_an_open_sensor_fault_suppresses_dry_and_over_until_it_resolves(): void
    {
        $fault = Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::SensorFault, 'severity' => AlertSeverity::Warning]);

        // A flaky sensor: dry and over values between broken ones. The fault stays open
        // (never 2 normal values in a row) and neither dry nor over is raised.
        $ingest = app(ReadingIngestor::class);
        foreach ([$this->raw(10), 4095, $this->raw(90), 4095, $this->raw(10), 0] as $raw) {
            $this->travel(5)->seconds();
            $ingest->ingest($this->node->fresh(), ['moisture' => $raw]);
        }

        $this->assertSame(AlertStatus::Open, $fault->refresh()->status);
        $this->assertNull($this->open(AlertType::Dry));
        $this->assertNull($this->open(AlertType::Over));

        // One normal value: the fault stays and dry is still suppressed.
        $this->send(10);
        $this->assertSame(AlertStatus::Open, $fault->refresh()->status);
        $this->assertNull($this->open(AlertType::Dry));

        // Two normal values resolve the fault; the same two valid dry readings then raise dry.
        $this->send(11);
        $this->assertSame(AlertStatus::Resolved, $fault->refresh()->status);
        $this->assertNotNull($this->open(AlertType::Dry));
    }

    // ---- park status ----

    public function test_park_status_order_offline_dry_over_ok(): void
    {
        $service = app(ParkStatusService::class);
        $this->node->update(['last_seen_at' => now()]);
        $this->assertSame(ParkStatus::Ok, $service->compute($this->park));

        Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::Over]);
        $this->assertSame(ParkStatus::Over, $service->compute($this->park));

        Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::Dry]);
        $this->assertSame(ParkStatus::Dry, $service->compute($this->park));

        Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::Leak]);
        $this->assertSame(ParkStatus::Dry, $service->compute($this->park), 'leak does not change the colour');

        $this->node->update(['last_seen_at' => now()->subMinutes(10)]);
        $this->assertSame(ParkStatus::Offline, $service->compute($this->park));
    }

    public function test_status_changed_at_only_moves_on_a_real_change(): void
    {
        $this->send(50);
        $park = $this->park->refresh();
        $this->assertSame(ParkStatus::Ok, $park->status);
        $changedAt = $park->status_changed_at;

        $this->send(55);
        $this->assertEquals($changedAt, $this->park->refresh()->status_changed_at);
    }
}
