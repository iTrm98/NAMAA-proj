<?php

namespace Tests\Feature\Pump;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\PumpReason;
use App\Enums\TaskStatus;
use App\Events\PumpChanged;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\Task;
use App\Models\User;
use App\Services\PumpService;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PumpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $this->worker = User::factory()->worker()->create(['company_id' => $company->id]);
        $this->park = Park::factory()->create(['company_id' => $company->id, 'moisture_min' => 25, 'moisture_max' => 70]);
        $this->node = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'pump_target' => false, 'last_seen_at' => null]);
    }

    private function reading(float $pct, bool $pumpOn = false): void
    {
        $this->travel(5)->seconds();
        app(ReadingIngestor::class)->ingest($this->node->fresh(), [
            'moisture' => (int) round(3200 - $pct / 100 * 1900), 'pump_on' => $pumpOn, 'flow' => $pumpOn ? 2.0 : 0.0,
        ]);
    }

    // ---- E1: manual pump ----

    public function test_admin_switches_the_pump_on_and_off_with_logs(): void
    {
        Event::fake([PumpChanged::class]);

        $this->asUser($this->admin)->postJson("/api/admin/parks/{$this->park->id}/pump", ['on' => true])
            ->assertOk()->assertJsonPath('data.target', true)->assertJsonPath('data.reason', 'manual')
            ->assertJsonPath('data.by.id', $this->admin->id)->assertJsonPath('data.node_id', $this->node->id);
        $this->assertNotNull($this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}")->json('data.pump.cutoff_at'));

        $this->asUser($this->admin)->postJson("/api/admin/parks/{$this->park->id}/pump", ['on' => false])
            ->assertOk()->assertJsonPath('data.target', false)->assertJsonPath('data.cutoff_at', null);

        $this->assertSame(2, PumpLog::where('node_id', $this->node->id)->where('user_id', $this->admin->id)->count());
        $this->assertSame(2, ActivityLog::where('action', 'pump.switched')->count());
        Event::assertDispatched(PumpChanged::class, fn ($e) => $e->broadcastAs() === 'pump.changed' && $e->payload['pump_target'] === true);

        // The board gets the target in its next reading reply.
        $this->asUser($this->admin)->postJson("/api/admin/parks/{$this->park->id}/pump", ['on' => true]);
        $token = $this->node->createToken('device', ['device'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $this->node->id, 'moisture' => 2250])
            ->assertCreated()->assertJsonPath('pump', true);
    }

    public function test_admin_pump_rules(): void
    {
        $this->asUser($this->admin)->postJson("/api/admin/parks/{$this->park->id}/pump", [])->assertStatus(422)->assertJsonValidationErrors('on');

        $noPump = Park::factory()->create(['company_id' => $this->admin->company_id]);
        Node::factory()->create(['park_id' => $noPump->id]);
        $this->asUser($this->admin)->postJson("/api/admin/parks/{$noPump->id}/pump", ['on' => true])->assertStatus(422);

        $this->asUser(User::factory()->admin()->create())->postJson("/api/admin/parks/{$this->park->id}/pump", ['on' => true])->assertNotFound();
        $this->asUser($this->worker)->postJson("/api/admin/parks/{$this->park->id}/pump", ['on' => true])->assertForbidden();
        $this->assertFalse($this->node->refresh()->pump_target);
    }

    public function test_worker_pump_only_on_own_in_progress_task(): void
    {
        $open = Task::factory()->create(['park_id' => $this->park->id, 'assigned_to' => $this->worker->id]);
        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$open->id}/pump", ['on' => true])->assertForbidden();

        $other = User::factory()->worker()->create(['company_id' => $this->admin->company_id]);
        $theirs = Task::factory()->inProgress()->create(['park_id' => $this->park->id, 'assigned_to' => $other->id]);
        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$theirs->id}/pump", ['on' => true])->assertNotFound();

        $mine = Task::factory()->inProgress()->create(['park_id' => $this->park->id, 'assigned_to' => $this->worker->id]);
        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$mine->id}/pump", ['on' => true])->assertOk()->assertJsonPath('data.pump.target', true);
        $this->assertDatabaseHas('pump_logs', ['node_id' => $this->node->id, 'user_id' => $this->worker->id, 'target' => true, 'reason' => 'manual']);

        $mine->update(['status' => TaskStatus::Done]);
        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$mine->id}/pump", ['on' => false])->assertForbidden();

        $noPumpPark = Park::factory()->create(['company_id' => $this->admin->company_id]);
        $noPumpTask = Task::factory()->inProgress()->create(['park_id' => $noPumpPark->id, 'assigned_to' => $this->worker->id]);
        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$noPumpTask->id}/pump", ['on' => true])->assertStatus(422);
    }

    public function test_safety_cut_off_after_the_maximum_run_time(): void
    {
        app(PumpService::class)->set($this->node, true, PumpReason::Manual, $this->admin);

        $this->travel(14)->minutes();
        $this->artisan('namaa:pump-safety')->assertSuccessful();
        $this->assertTrue($this->node->refresh()->pump_target, 'still inside 15 min');

        $this->travel(2)->minutes();
        $this->artisan('namaa:pump-safety')->assertSuccessful();
        $this->assertFalse($this->node->refresh()->pump_target);
        $log = PumpLog::latest('id')->first();
        $this->assertSame(PumpReason::Safety, $log->reason);
        $this->assertNull($log->user_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'pump.switched', 'subject_id' => $this->node->id]);
    }

    public function test_safety_cut_off_is_15_minutes_in_demo_mode_too(): void
    {
        // Sprint 3.1: the simulated parks irrigate in real time (about 12.5 min a session).
        config(['namaa.demo_mode' => true]);
        app(PumpService::class)->set($this->node, true, PumpReason::Manual);

        $this->travel(899)->seconds();
        $this->assertSame(0, app(PumpService::class)->cutOffLongRuns());
        $this->travel(2)->seconds();
        $this->assertSame(1, app(PumpService::class)->cutOffLongRuns());
    }

    // ---- E2: automatic irrigation ----

    public function test_admin_toggles_auto_irrigation_with_a_log(): void
    {
        $this->asUser($this->admin)->patchJson("/api/admin/parks/{$this->park->id}", ['auto_irrigation' => true])
            ->assertOk()->assertJsonPath('data.auto_irrigation', true);

        $this->assertDatabaseHas('activity_logs', ['action' => 'park.updated', 'subject_id' => $this->park->id]);
        $log = ActivityLog::where('action', 'park.updated')->sole();
        $this->assertSame(['before' => ['auto_irrigation' => false], 'after' => ['auto_irrigation' => true]], $log->diff);

        $noPump = Park::factory()->create(['company_id' => $this->admin->company_id]);
        $this->asUser($this->admin)->patchJson("/api/admin/parks/{$noPump->id}", ['auto_irrigation' => true])
            ->assertStatus(422)->assertJsonValidationErrors('auto_irrigation');
    }

    public function test_auto_irrigation_turns_the_pump_on_at_dry_and_off_at_mid_range(): void
    {
        $this->park->update(['auto_irrigation' => true]);

        $this->reading(20);
        $this->assertFalse($this->node->refresh()->pump_target);
        $this->reading(20); // dry raised (2 readings) -> pump on

        $alert = Alert::open()->where('type', AlertType::Dry)->sole();
        $this->assertSame(AlertSeverity::Info, $alert->severity, 'auto mode: the system handles it');
        $this->assertTrue($alert->data['auto_irrigation']);
        $this->assertTrue($this->node->refresh()->pump_target);
        $this->assertSame(PumpReason::Rule, PumpLog::latest('id')->first()->reason);

        $this->reading(30, true);
        $this->reading(40, true);
        $this->assertTrue($this->node->refresh()->pump_target, 'below the middle (47.5) it keeps running');
        $this->reading(48, true);
        $this->assertFalse($this->node->refresh()->pump_target);
        $off = PumpLog::latest('id')->first();
        $this->assertSame(PumpReason::Rule, $off->reason);
        $this->assertFalse($off->target);
    }

    public function test_auto_irrigation_does_not_restart_a_pump_stopped_for_safety(): void
    {
        $this->park->update(['auto_irrigation' => true]);
        foreach ([20, 20, 20] as $pct) {
            $this->reading($pct);
        }
        app(PumpService::class)->set($this->node->refresh(), false, PumpReason::Safety);

        $this->reading(20);
        $this->assertFalse($this->node->refresh()->pump_target);
    }

    public function test_auto_irrigation_never_runs_without_a_pump_or_when_off(): void
    {
        foreach ([20, 20, 20] as $pct) {
            $this->reading($pct);
        }
        $this->assertFalse($this->node->refresh()->pump_target, 'auto off: no pump');
        $this->assertSame(AlertSeverity::Critical, Alert::open()->where('type', AlertType::Dry)->sole()->severity);

        $park = Park::factory()->create(['auto_irrigation' => true]);
        $sensor = Node::factory()->create(['park_id' => $park->id, 'has_pump' => false]);
        foreach ([20, 20, 20] as $pct) {
            $this->travel(5)->seconds();
            app(ReadingIngestor::class)->ingest($sensor->fresh(), ['moisture' => (int) round(3200 - $pct * 19)]);
        }
        $this->assertSame(0, PumpLog::count());
        $this->assertSame(AlertSeverity::Critical, Alert::open()->where('node_id', $sensor->id)->sole()->severity);
    }
}
