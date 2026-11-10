<?php

namespace Tests\Feature\Tasks;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkerTaskFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ahmed;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $this->ahmed = User::factory()->worker()->create(['company_id' => $company->id, 'name' => 'Ahmed']);
        $this->park = Park::factory()->simulated()->create(['company_id' => $company->id, 'moisture_min' => 25, 'moisture_max' => 70]);
        $this->node = Node::factory()->simulated()->withPump()->create(['park_id' => $this->park->id, 'last_seen_at' => null]);
        Storage::fake('local');
    }

    private function reading(float $pct, bool $pump = false): void
    {
        $this->travel(5)->seconds();
        app(ReadingIngestor::class)->ingest($this->node->fresh(), [
            'moisture' => (int) round(3200 - $pct / 100 * 1900), 'pump_on' => $pump, 'flow' => $pump ? 2.0 : 0.0,
        ]);
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->create($attributes + ['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id]);
    }

    public function test_the_full_closed_loop_ends_verified_with_the_time_to_resolve(): void
    {
        Queue::fake();

        // Sensor readings -> the dry rule raises an alert.
        foreach ([21, 20, 20] as $pct) {
            $this->reading($pct);
        }
        $alert = Alert::open()->where('type', AlertType::Dry)->sole();
        $this->assertSame(ParkStatus::Dry, $this->park->refresh()->status);

        // Admin creates a task from the alert for Ahmed.
        $this->travel(2)->minutes();
        $taskId = $this->asUser($this->admin)->postJson('/api/admin/tasks', [
            'alert_id' => $alert->id, 'assigned_to' => $this->ahmed->id, 'title' => 'Irrigate',
        ])->assertCreated()->json('data.id');

        // Ahmed sees it, starts it and switches the pump on.
        $this->asUser($this->ahmed)->getJson('/api/worker/tasks')->assertOk()->assertJsonPath('data.0.id', $taskId);
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$taskId}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$taskId}/pump", ['on' => true])->assertOk()->assertJsonPath('data.pump.target', true);
        $this->assertDatabaseHas('pump_logs', ['node_id' => $this->node->id, 'user_id' => $this->ahmed->id, 'target' => true, 'reason' => 'manual']);

        // Watering: moisture climbs past min + margin (30), the dry alert resolves on its own.
        foreach ([24, 27, 30, 32] as $pct) {
            $this->reading($pct, pump: true);
        }
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);

        // Done with a note and a photo; the pump is switched off; verification is queued with the delay.
        $this->asUser($this->ahmed)->post("/api/worker/tasks/{$taskId}/done", [
            'note' => 'Opened the valve', 'photo' => $this->fakeImage('after.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.has_photo', true);

        $task = Task::find($taskId);
        $this->assertSame('Opened the valve', $task->done_note);
        Storage::disk('local')->assertExists($task->done_photo_path);
        $this->assertStringStartsWith('task-photos/', $task->done_photo_path);
        $this->assertFalse($this->node->refresh()->pump_target);
        $this->assertNull($task->verified, 'Done alone proves nothing');

        // Readings after done_at stay in range for the 10 min window -> verified by the scheduled check.
        for ($i = 0; $i < 6; $i++) {
            $this->reading(33);
            $this->travel(55)->seconds();
        }
        $this->artisan('namaa:verify-tasks')->assertSuccessful();
        $this->assertNull($task->refresh()->verified, 'about 5 min of good readings: still waiting');

        for ($i = 0; $i < 6; $i++) {
            $this->reading(33);
            $this->travel(55)->seconds();
        }
        $this->artisan('namaa:verify-tasks')->assertSuccessful();

        $task->refresh();
        $this->assertTrue($task->verified);
        $this->assertNotNull($task->verified_at);
        $this->assertNull($task->verify_reason);
        $this->assertGreaterThanOrEqual(3, $task->time_to_resolve_min);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.verified', 'subject_id' => $taskId]);
        foreach (['task.created', 'task.assigned', 'task.started', 'task.done', 'pump.switched'] as $action) {
            $this->assertDatabaseHas('activity_logs', ['action' => $action]);
        }

        // The admin sees the result.
        $this->asUser($this->admin)->getJson("/api/admin/tasks/{$taskId}")->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.time_to_resolve_minutes', $task->time_to_resolve_min);
    }

    public function test_worker_sees_only_own_tasks_and_others_are_404(): void
    {
        $khalid = User::factory()->worker()->create(['company_id' => $this->ahmed->company_id]);
        $mine = $this->task();
        $theirs = $this->task(['assigned_to' => $khalid->id]);

        $this->asUser($this->ahmed)->getJson('/api/worker/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->asUser($this->ahmed)->getJson("/api/worker/tasks/{$theirs->id}")->assertNotFound();
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$theirs->id}/start")->assertNotFound();
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$theirs->id}/done")->assertNotFound();
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$theirs->id}/pump", ['on' => true])->assertNotFound();
        $this->assertSame(TaskStatus::Open, $theirs->refresh()->status);
    }

    public function test_worker_detail_has_the_park_latest_reading_alert_and_pump(): void
    {
        $this->reading(40);
        $alert = Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => AlertType::Dry]);
        $task = $this->task(['alert_id' => $alert->id]);

        $this->asUser($this->ahmed)->getJson("/api/worker/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.alert.type', 'dry')
            ->assertJsonPath('data.alert.recommended_action', 'action.dry')
            ->assertJsonPath('data.park_detail.has_pump', true)
            ->assertJsonPath('data.park_detail.latest.moisture_pct', 40)
            ->assertJsonPath('data.park_detail.source', 'simulated')
            ->assertJsonPath('data.pump.target', false);
    }

    public function test_worker_list_orders_active_by_priority_then_recent_done(): void
    {
        $normal = $this->task(['priority' => 'normal', 'created_at' => now()->subHours(3)]);
        $urgent = $this->task(['priority' => 'urgent', 'created_at' => now()->subHour()]);
        $done = $this->task(['status' => TaskStatus::Done, 'done_at' => now(), 'priority' => 'urgent']);

        $ids = collect($this->asUser($this->ahmed)->getJson('/api/worker/tasks')->json('data'))->pluck('id')->all();
        $this->assertSame([$urgent->id, $normal->id, $done->id], $ids);
    }

    public function test_status_rules_cancelled_cannot_start_done_needs_in_progress(): void
    {
        $cancelled = $this->task(['status' => TaskStatus::Cancelled]);
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$cancelled->id}/start")->assertStatus(422);

        $open = $this->task();
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$open->id}/done", ['note' => 'x'])->assertStatus(422);
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$open->id}/start")->assertOk();
        $this->asUser($this->ahmed)->postJson("/api/worker/tasks/{$open->id}/start")->assertStatus(422);
    }

    public function test_photo_validation_and_authenticated_download(): void
    {
        Queue::fake();
        $task = $this->task(['status' => TaskStatus::InProgress, 'started_at' => now()]);

        $this->asUser($this->ahmed)->post("/api/worker/tasks/{$task->id}/done", ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->asUser($this->ahmed)->post("/api/worker/tasks/{$task->id}/done", ['photo' => $this->fakeImage('big.png', 6 * 1024 * 1024)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->asUser($this->ahmed)->post("/api/worker/tasks/{$task->id}/done", ['note' => str_repeat('a', 1001)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('note');

        $this->asUser($this->ahmed)->post("/api/worker/tasks/{$task->id}/done", ['photo' => $this->fakeImage('ok.webp')], ['Accept' => 'application/json'])->assertOk();

        $this->asUser($this->ahmed)->get("/api/worker/tasks/{$task->id}/photo")->assertOk();
        $this->asUser($this->admin)->get("/api/admin/tasks/{$task->id}/photo")->assertOk();
        $this->asUser(User::factory()->admin()->create())->getJson("/api/admin/tasks/{$task->id}/photo")->assertNotFound();
        $khalid = User::factory()->worker()->create(['company_id' => $this->ahmed->company_id]);
        $this->asUser($khalid)->getJson("/api/worker/tasks/{$task->id}/photo")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson("/api/admin/tasks/{$task->id}/photo")->assertUnauthorized();
    }

    public function test_admin_role_cannot_use_worker_routes(): void
    {
        $task = $this->task();
        $this->asUser($this->admin)->getJson('/api/worker/tasks')->assertForbidden();
        $this->asUser($this->admin)->postJson("/api/worker/tasks/{$task->id}/start")->assertForbidden();
    }
}
