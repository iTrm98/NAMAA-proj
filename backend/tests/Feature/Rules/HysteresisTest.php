<?php

namespace Tests\Feature\Rules;

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
use Tests\TestCase;

/**
 * Sprint 3, A1: noisy sensors around a limit must not flap alerts or restart
 * verification. moisture_min 25, moisture_max 70, margin 5:
 * dry raises < 25 and resolves >= 30; over resolves <= 65; the verification
 * window starts at >= 30 and is broken only below 25.
 */
class HysteresisTest extends TestCase
{
    use RefreshDatabase;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->park = Park::factory()->create(['moisture_min' => 25, 'moisture_max' => 70]);
        $this->node = Node::factory()->withPump()->create(['park_id' => $this->park->id]);
    }

    private function send(float $pct, int $step = 60, array $extra = []): void
    {
        $this->travel($step)->seconds();
        app(ReadingIngestor::class)->ingest($this->node->fresh(), ['moisture' => (int) round(3200 - $pct / 100 * 1900)] + $extra);
    }

    public function test_margin_is_configurable_with_a_default_of_five(): void
    {
        $this->assertSame(5, config('namaa.rules.moisture_recovery_margin'));
    }

    public function test_noise_around_the_minimum_does_not_flap_the_dry_alert(): void
    {
        $this->send(22);
        $this->send(22);
        $alert = Alert::open()->where('type', AlertType::Dry)->sole();

        // A park watered to about the minimum: 24..28 noise.
        foreach ([26, 28, 24, 27, 26, 28, 25, 29, 27] as $pct) {
            $this->send($pct);
        }

        $this->assertSame(AlertStatus::Open, $alert->refresh()->status, 'never 2 readings at >= 30');
        $this->assertSame(1, Alert::where('type', AlertType::Dry)->count(), 'one alert, no flapping');

        $this->send(30);
        $this->send(31);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_over_resolves_only_at_max_minus_margin(): void
    {
        $on = ['pump_on' => true, 'flow' => 1.8];
        foreach ([80, 80, 80] as $pct) {
            $this->send($pct, 60, $on);
        }
        $alert = Alert::open()->where('type', AlertType::Over)->sole();

        foreach ([69, 68, 67, 66] as $pct) {
            $this->send($pct);
        }
        $this->assertSame(AlertStatus::Open, $alert->refresh()->status);

        $this->send(65);
        $this->send(64);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    private function doneDryTask(): Task
    {
        $alert = Alert::factory()->create([
            'park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::Dry, 'created_at' => now()->subMinutes(10),
        ]);

        return Task::factory()->create([
            'park_id' => $this->park->id, 'alert_id' => $alert->id, 'status' => TaskStatus::Done, 'done_at' => now(),
        ]);
    }

    private function verify(Task $task): ?bool
    {
        return app(TaskVerifier::class)->verify($task->fresh());
    }

    public function test_the_window_starts_only_at_the_minimum_plus_margin(): void
    {
        $task = $this->doneDryTask();

        // 15 min between 25 and 30: the soil is not clearly recovered, no window yet.
        for ($i = 0; $i < 15; $i++) {
            $this->send(27);
        }
        $this->assertNull($this->verify($task));

        // Now it passes 30: the window starts here and runs 10 min.
        $this->send(31);
        for ($i = 0; $i < 9; $i++) {
            $this->send(31);
        }
        $this->assertNull($this->verify($task), '9 min since 31');
        $this->send(31);
        $this->assertTrue($this->verify($task));
    }

    public function test_noise_between_the_minimum_and_the_margin_keeps_the_window_running(): void
    {
        $task = $this->doneDryTask();

        // Starts at 31, then noisy readings 26..29 (above the min): the window keeps running.
        foreach ([31, 28, 26, 29, 27, 30, 26, 28, 27, 29, 28] as $pct) {
            $this->send($pct);
        }

        $this->assertTrue($this->verify($task), '10 min since the start, never below 25');
        $this->assertNull($task->refresh()->verify_reason);
    }

    public function test_a_real_drop_below_the_minimum_still_breaks_the_window(): void
    {
        $task = $this->doneDryTask();

        foreach ([31, 30, 29, 28, 27, 26] as $pct) {
            $this->send($pct);
        }
        $this->send(23); // below the minimum: the window breaks
        foreach ([27, 27, 27, 27, 27, 27, 27, 27, 27, 27, 27] as $pct) {
            $this->send($pct); // between 25 and 30: no new window starts
        }
        $this->assertNull($this->verify($task));

        $this->travel(50)->minutes();
        $this->assertFalse($this->verify($task));
        $this->assertSame('condition_returned', $task->refresh()->verify_reason);
    }

    public function test_demo_mode_noise_at_the_minimum_verifies_in_about_one_minute(): void
    {
        config(['namaa.demo_mode' => true]);
        $task = $this->doneDryTask();

        // Done at the watering target, then noise down towards the minimum (5 s readings).
        foreach ([47, 40, 33, 29, 27, 26, 28, 26, 27, 26, 28, 27, 26] as $pct) {
            $this->send($pct, 5);
        }

        $this->assertTrue($this->verify($task), '60 s window, no restart from noise above 25');
    }
}
