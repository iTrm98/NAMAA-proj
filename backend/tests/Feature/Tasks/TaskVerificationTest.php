<?php

namespace Tests\Feature\Tasks;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Services\ReadingIngestor;
use App\Services\TaskVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 2.5, phase E: a done task is verified only when the alert's
 * condition stays cleared for a continuous window of readings.
 * Normal timings: window 10 min, timeout 60 min, gap limit 5 min.
 */
class TaskVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Park $park;

    private Node $node;

    private Alert $alert;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->park = Park::factory()->create(['moisture_min' => 25, 'moisture_max' => 70]);
        $this->node = Node::factory()->withPump()->create(['park_id' => $this->park->id]);
        $this->alert = Alert::factory()->create([
            'park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::Dry, 'created_at' => now()->subMinutes(30),
        ]);
        $this->task = Task::factory()->create([
            'park_id' => $this->park->id, 'alert_id' => $this->alert->id, 'status' => TaskStatus::Done, 'done_at' => now(),
        ]);
    }

    /** Readings at a moisture % every $step seconds. */
    private function readings(float $pct, int $count, int $step = 60): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->travel($step)->seconds();
            app(ReadingIngestor::class)->ingest($this->node->fresh(), ['moisture' => (int) round(3200 - $pct / 100 * 1900)]);
        }
    }

    private function check(): ?bool
    {
        return app(TaskVerifier::class)->verify($this->task->fresh());
    }

    public function test_verified_after_a_continuous_window_of_normal_readings(): void
    {
        $this->readings(35, 10); // 9 min between the first and the last
        $this->assertNull($this->check(), 'window not reached yet');
        $this->assertNull($this->task->refresh()->verified);

        $this->readings(35, 1); // 10 min
        $this->assertTrue($this->check());

        $this->task->refresh();
        $this->assertTrue($this->task->verified);
        $this->assertNull($this->task->verify_reason);
        $this->assertSame(41, $this->task->time_to_resolve_min, 'alert raised (30 min before Done) -> verified');
        $this->assertSame(AlertStatus::Resolved, $this->alert->refresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.verified', 'subject_id' => $this->task->id]);

        $this->assertNull($this->check(), 'decided once');
    }

    public function test_a_bad_reading_inside_the_window_restarts_it(): void
    {
        $this->readings(35, 6);
        $this->readings(20, 1); // the soil is dry again
        $this->readings(35, 10); // 9 min since the restart
        $this->assertNull($this->check());

        $this->readings(35, 1);
        $this->assertTrue($this->check());
    }

    public function test_a_gap_in_the_readings_restarts_the_window(): void
    {
        $this->readings(35, 5);
        $this->readings(35, 1, 6 * 60); // 6 min without a reading (offline window is 5 min)
        $this->readings(35, 9);
        $this->assertNull($this->check(), '9 min since the gap');
        $this->readings(35, 1);
        $this->assertTrue($this->check());
    }

    public function test_not_verified_after_the_timeout_when_the_condition_returned(): void
    {
        $this->readings(35, 5);
        $this->readings(20, 1);
        $this->readings(35, 5);
        $this->readings(20, 1);
        $this->assertNull($this->check());

        $this->travel(50)->minutes(); // 62 min after Done
        $this->assertFalse($this->check());

        $this->task->refresh();
        $this->assertFalse($this->task->verified);
        $this->assertSame('condition_returned', $this->task->verify_reason);
        $this->assertNull($this->task->time_to_resolve_min);
        $this->assertFalse(
            DB::table('activity_logs')->where('action', 'alert.resolved')->where('diff', 'like', '%task_verified%')->exists(),
            'a failed verification never closes the alert',
        );
    }

    public function test_no_data_is_not_verified_after_the_timeout(): void
    {
        $this->travel(59)->minutes();
        $this->assertNull($this->check(), 'still waiting before the timeout');

        $this->travel(2)->minutes();
        $this->assertFalse($this->check());
        $this->assertSame('no_data', $this->task->refresh()->verify_reason);
        $this->assertSame(AlertStatus::Open, $this->alert->refresh()->status);
    }

    public function test_demo_timings_window_one_minute_timeout_three_minutes(): void
    {
        config(['namaa.demo_mode' => true]);

        $this->readings(35, 12, 5); // 55 s
        $this->assertNull($this->check());
        $this->readings(35, 1, 5); // 60 s
        $this->assertTrue($this->check());

        $other = Task::factory()->create(['park_id' => $this->park->id, 'status' => TaskStatus::Done, 'done_at' => now()]);
        $this->travel(181)->seconds();
        $this->assertFalse(app(TaskVerifier::class)->verify($other));
        $this->assertSame('no_data', $other->refresh()->verify_reason);
    }

    public function test_heat_stress_is_verified_like_dry_and_the_scheduled_command_checks_all_waiting_tasks(): void
    {
        $this->alert->update(['type' => AlertType::HeatStress]);
        $this->readings(35, 11);

        $this->artisan('namaa:verify-tasks')->assertSuccessful();

        $this->assertTrue($this->task->refresh()->verified);
    }
}
