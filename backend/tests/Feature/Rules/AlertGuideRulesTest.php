<?php

namespace Tests\Feature\Rules;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\AlertUpdated;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use App\Services\OfflineChecker;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Sprint 2.5, phase B: rule changes from the team's alert guide. */
class AlertGuideRulesTest extends TestCase
{
    use RefreshDatabase;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->park = Park::factory()->create(['moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Ok]);
        $this->node = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'dry_value' => 3200, 'wet_value' => 1300]);
    }

    private function raw(float $pct): int
    {
        return (int) round(3200 - $pct / 100 * 1900);
    }

    /** @param array<string, mixed> $extra */
    private function send(?float $pct, array $extra = [], ?Node $node = null, int $seconds = 5): void
    {
        $this->travel($seconds)->seconds();
        app(ReadingIngestor::class)->ingest(($node ?? $this->node)->fresh(), ['moisture' => $pct === null ? null : $this->raw($pct)] + $extra);
    }

    private function open(AlertType $type): ?Alert
    {
        return Alert::open()->where('type', $type)->first();
    }

    // ---- B1 dry ----

    public function test_new_parks_default_to_moisture_min_30_existing_values_kept(): void
    {
        $id = DB::table('parks')->insertGetId(collect(Park::factory()->raw())->except(['moisture_min'])
            ->map(fn ($v) => is_array($v) ? json_encode($v) : ($v instanceof \BackedEnum ? $v->value : $v))->all());

        $this->assertSame(30, Park::find($id)->moisture_min);
        $this->assertSame(25, $this->park->refresh()->moisture_min);
    }

    public function test_dry_needs_two_readings_and_resolves_after_two(): void
    {
        $this->send(20);
        $this->assertNull($this->open(AlertType::Dry));
        $this->send(20);
        $alert = $this->open(AlertType::Dry);
        $this->assertNotNull($alert);
        $this->assertSame(2, $alert->data['readings']);

        $this->send(30);
        $this->assertSame(AlertStatus::Open, $alert->refresh()->status);
        $this->send(30);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    // ---- B2 heat stress ----

    public function test_heat_stress_converts_the_dry_alert_in_place_and_its_task_becomes_urgent(): void
    {
        Event::fake([AlertUpdated::class]);
        $this->send(20, ['temperature' => 35.0]);
        $this->send(20, ['temperature' => 36.0]);
        $alert = $this->open(AlertType::Dry);
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id, 'priority' => TaskPriority::High, 'due_at' => now()->addHours(6)]);

        $this->travel(1)->minutes();
        $this->send(19, ['temperature' => 41.5]);

        $this->assertSame(1, Alert::count(), 'one alert per problem: no second alert');
        $alert->refresh();
        $this->assertSame(AlertType::HeatStress, $alert->type);
        $this->assertSame(TaskPriority::Urgent, $alert->priority);
        $this->assertSame(41.5, $alert->data['heat_temperature']);
        $this->assertSame(ParkStatus::Dry, $this->park->refresh()->status);

        $task->refresh();
        $this->assertSame(TaskPriority::Urgent, $task->priority);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $task->due_at->timestamp, 2, 'new due time: 1 h');

        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.escalated', 'subject_id' => $alert->id]);
        $log = DB::table('activity_logs')->where('action', 'alert.escalated')->first();
        $this->assertSame('heat', json_decode($log->diff, true)['after']['reason']);
        $this->assertSame('dry', json_decode($log->diff, true)['before']['type']);
        Event::assertDispatched(AlertUpdated::class, fn ($e) => $e->payload['alert']['type'] === 'heat_stress' && $e->payload['alert']['priority'] === 'urgent');
    }

    public function test_heat_stress_needs_an_open_dry_alert_and_40_degrees(): void
    {
        $this->send(50, ['temperature' => 44.0]);
        $this->send(50, ['temperature' => 44.0]);
        $this->assertSame(0, Alert::count(), 'heat alone is not an alert');

        $this->send(20, ['temperature' => 39.9]);
        $this->send(20, ['temperature' => 39.9]);
        $this->assertNotNull($this->open(AlertType::Dry));
        $this->send(20, ['temperature' => 40.0]);
        $this->assertNull($this->open(AlertType::Dry));
        $this->assertNotNull($this->open(AlertType::HeatStress));
    }

    public function test_heat_stress_stays_after_the_temperature_drops_until_the_moisture_recovers(): void
    {
        $this->send(20, ['temperature' => 42.0]);
        $this->send(20, ['temperature' => 42.0]);
        $alert = $this->open(AlertType::HeatStress);
        $this->assertNotNull($alert);

        $this->send(18, ['temperature' => 30.0]);
        $this->send(17, ['temperature' => 29.0]);
        $alert->refresh();
        $this->assertSame(AlertType::HeatStress, $alert->type, 'no drop back to dry');
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame(1, Alert::count());

        $this->send(30, ['temperature' => 29.0]);
        $this->send(31, ['temperature' => 29.0]);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
        $this->assertSame(ParkStatus::Ok, $this->park->refresh()->status);
    }

    // ---- B3 overwatering ----

    public function test_over_on_a_pump_node_raises_only_while_irrigating(): void
    {
        foreach (range(1, 6) as $i) {
            $this->send(85, ['pump_on' => false, 'flow' => 0.0]);
        }
        $this->assertNull($this->open(AlertType::Over), 'wet soil without irrigation is not our fault');

        $this->send(86, ['pump_on' => true, 'flow' => 1.9]);
        $this->send(87, ['pump_on' => true, 'flow' => 2.0]);
        $this->assertNull($this->open(AlertType::Over));
        $this->send(88, ['pump_on' => false, 'flow' => 0.4]); // pump just off, water still running: still irrigating
        $alert = $this->open(AlertType::Over);
        $this->assertNotNull($alert);
        $this->assertSame(3, $alert->data['readings']);
    }

    public function test_over_on_a_node_without_pump_or_flow_needs_six_readings(): void
    {
        $node = Node::factory()->create(['park_id' => $this->park->id, 'has_pump' => false, 'dry_value' => 3200, 'wet_value' => 1300]);

        foreach (range(1, 5) as $i) {
            $this->send(85, [], $node);
        }
        $this->assertNull($this->open(AlertType::Over));
        $this->send(85, [], $node);
        $alert = $this->open(AlertType::Over);
        $this->assertNotNull($alert);
        $this->assertSame(6, $alert->data['readings']);

        $this->send(60, [], $node);
        $this->send(60, [], $node);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    // ---- B4 offline ----

    public function test_offline_shows_at_once_and_becomes_task_ready_after_the_delay(): void
    {
        Event::fake([AlertUpdated::class]);
        $this->node->update(['last_seen_at' => now()]);
        $this->travel(6)->minutes();
        app(OfflineChecker::class)->run();

        $alert = $this->open(AlertType::Offline);
        $this->assertNotNull($alert);
        $this->assertSame(TaskPriority::Normal, $alert->priority);
        $this->assertNull($alert->task_ready_at, 'watching: no task yet');
        $this->assertSame(ParkStatus::Offline, $this->park->refresh()->status);

        // The API refuses a task while it is only watched.
        $admin = User::factory()->admin()->create(['company_id' => $this->park->company_id]);
        $this->asUser($admin)->postJson('/api/admin/tasks', ['alert_id' => $alert->id, 'title' => 'Restart'])
            ->assertUnprocessable()->assertJsonValidationErrors('alert_id');
        $this->getJson('/api/admin/alerts')->assertJsonPath('data.0.task_ready', false);

        $this->travel(23)->minutes(); // 29 min offline
        app(OfflineChecker::class)->run();
        $this->assertNull($alert->refresh()->task_ready_at);

        $this->travel(1)->minutes(); // 30 min offline
        app(OfflineChecker::class)->run();
        $this->assertNotNull($alert->refresh()->task_ready_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.task_ready', 'subject_id' => $alert->id]);
        Event::assertDispatched(AlertUpdated::class, fn ($e) => $e->payload['alert']['task_ready'] === true);

        $this->postJson('/api/admin/tasks', ['alert_id' => $alert->id, 'title' => 'Restart'])->assertCreated();
    }

    public function test_offline_task_ready_after_two_minutes_in_demo_mode(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->node->update(['last_seen_at' => now()]);
        $this->travel(31)->seconds();
        app(OfflineChecker::class)->run();
        $alert = $this->open(AlertType::Offline);
        $this->assertNotNull($alert);
        $this->assertNull($alert->task_ready_at);

        $this->travel(90)->seconds(); // 121 s offline
        app(OfflineChecker::class)->run();
        $this->assertNotNull($alert->refresh()->task_ready_at);
    }

    public function test_offline_rises_to_high_when_the_park_also_has_an_open_dry_alert(): void
    {
        $other = Node::factory()->create(['park_id' => $this->park->id, 'last_seen_at' => now()]);
        $this->node->update(['last_seen_at' => now()]);
        $this->travel(6)->minutes();
        $other->update(['last_seen_at' => now()]);
        app(OfflineChecker::class)->run();
        $alert = $this->open(AlertType::Offline);
        $this->assertSame($this->node->id, $alert->node_id);
        $this->assertSame(TaskPriority::Normal, $alert->priority);

        Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $other->id, 'type' => AlertType::Dry]);
        app(OfflineChecker::class)->run();

        $this->assertSame(TaskPriority::High, $alert->refresh()->priority);
        $log = DB::table('activity_logs')->where('action', 'alert.escalated')->where('subject_id', $alert->id)->first();
        $this->assertSame('blind_on_dry', json_decode($log->diff, true)['after']['reason']);
    }

    public function test_offline_rises_to_high_after_six_hours(): void
    {
        $this->node->update(['last_seen_at' => now()]);
        $this->travel(6)->minutes();
        app(OfflineChecker::class)->run();
        $alert = $this->open(AlertType::Offline);

        $this->travel(5)->hours();
        app(OfflineChecker::class)->run();
        $this->assertSame(TaskPriority::Normal, $alert->refresh()->priority);

        $this->travel(55)->minutes(); // 6 h 1 min offline
        app(OfflineChecker::class)->run();
        $this->assertSame(TaskPriority::High, $alert->refresh()->priority);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'alert.escalated')->count(), 'raised once');

        app(OfflineChecker::class)->run();
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'alert.escalated')->count());
    }

    public function test_offline_long_delay_is_five_minutes_in_demo_mode(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->node->update(['last_seen_at' => now()]);
        $this->travel(31)->seconds();
        app(OfflineChecker::class)->run();
        $alert = $this->open(AlertType::Offline);

        $this->travel(4)->minutes();
        app(OfflineChecker::class)->run();
        $this->assertSame(TaskPriority::Normal, $alert->refresh()->priority);
        $this->travel(30)->seconds();
        app(OfflineChecker::class)->run();
        $this->assertSame(TaskPriority::High, $alert->refresh()->priority);
    }

    // ---- B5 stuck sensor ----

    public function test_a_raw_value_stuck_for_an_hour_is_a_sensor_fault(): void
    {
        $raw = $this->raw(40);
        $ingest = fn (int $value) => app(ReadingIngestor::class)->ingest($this->node->fresh(), ['moisture' => $value]);

        $ingest($raw + 3);
        for ($i = 0; $i < 119; $i++) { // 59.5 min of the same value at 30 s
            $this->travel(30)->seconds();
            $ingest($raw);
        }
        $this->assertNull($this->open(AlertType::SensorFault), 'not yet a full hour');

        // The same value from t+30 s on: stuck once that is a full hour ago.
        $this->travel(30)->seconds(); // t+3600: the window still holds the different first value
        $ingest($raw);
        $this->assertNull($this->open(AlertType::SensorFault));
        $this->travel(30)->seconds(); // t+3630
        $ingest($raw);
        $alert = $this->open(AlertType::SensorFault);
        $this->assertNotNull($alert);
        $this->assertSame('stuck', $alert->data['reason']);
        $this->assertSame(TaskPriority::Normal, $alert->priority);

        // The value moves again: resolved after 2 valid readings.
        $this->travel(30)->seconds();
        $ingest($raw - 4);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_stuck_window_is_two_minutes_in_demo_mode_and_needs_readings(): void
    {
        config(['namaa.demo_mode' => true]);
        $raw = $this->raw(40);
        $ingest = fn (int $value) => app(ReadingIngestor::class)->ingest($this->node->fresh(), ['moisture' => $value]);

        $ingest($raw);
        $this->travel(5)->seconds();
        $ingest($raw);
        $this->travel(3)->minutes(); // a gap, then the same value: no readings arrived in the window
        $ingest($raw);
        $this->assertNull($this->open(AlertType::SensorFault), 'stuck needs readings that keep arriving');

        Reading::query()->delete();
        Alert::query()->delete();
        $ingest($raw + 2);
        for ($i = 0; $i < 23; $i++) { // 115 s
            $this->travel(5)->seconds();
            $ingest($raw);
        }
        $this->assertNull($this->open(AlertType::SensorFault));
        $this->travel(5)->seconds();
        $ingest($raw);
        $this->assertNull($this->open(AlertType::SensorFault), 'the window still holds the first, different value');
        $this->travel(5)->seconds();
        $ingest($raw);
        $this->assertNotNull($this->open(AlertType::SensorFault));
        $this->assertNull($this->open(AlertType::Dry));
    }

    public function test_a_jittering_sensor_is_never_stuck(): void
    {
        $raw = $this->raw(40);
        for ($i = 0; $i < 150; $i++) {
            $this->travel(30)->seconds();
            app(ReadingIngestor::class)->ingest($this->node->fresh(), ['moisture' => $raw + ($i % 3 === 0 ? 1 : 0)]);
        }

        $this->assertNull($this->open(AlertType::SensorFault));
    }

    public function test_the_task_of_a_heat_stress_alert_is_listed_first(): void
    {
        $this->send(20, ['temperature' => 42.0]);
        $this->send(20, ['temperature' => 42.0]);
        $heat = $this->open(AlertType::HeatStress);
        $this->assertSame(TaskStatus::Open, Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $heat->id, 'priority' => TaskPriority::Urgent])->status);
        $this->assertSame($heat->id, $this->park->openAlerts()->first()->id);
    }
}
