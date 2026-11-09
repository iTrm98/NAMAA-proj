<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskEscalated;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Sprint 2.5, phase D: response times and escalation. */
class EscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Park $park;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $this->park = Park::factory()->create(['company_id' => $company->id]);
    }

    private function create(string $priority): Task
    {
        $id = $this->asUser($this->admin)->postJson('/api/admin/tasks', [
            'park_id' => $this->park->id, 'title' => 'Check', 'priority' => $priority,
        ])->assertCreated()->json('data.id');

        return Task::find($id);
    }

    public function test_due_at_follows_the_priority_response_time(): void
    {
        $expected = ['urgent' => 3600, 'high' => 6 * 3600, 'normal' => 48 * 3600, 'low' => 7 * 86400];

        foreach ($expected as $priority => $seconds) {
            $task = $this->create($priority);
            $this->assertEqualsWithDelta(now()->addSeconds($seconds)->timestamp, $task->due_at->timestamp, 2, $priority);
        }
    }

    public function test_due_at_uses_the_demo_response_times_in_demo_mode(): void
    {
        config(['namaa.demo_mode' => true]);
        $expected = ['urgent' => 120, 'high' => 240, 'normal' => 360, 'low' => 600];

        foreach ($expected as $priority => $seconds) {
            $task = $this->create($priority);
            $this->assertEqualsWithDelta(now()->addSeconds($seconds)->timestamp, $task->due_at->timestamp, 2, $priority);
        }
    }

    public function test_an_open_task_past_due_goes_up_one_level_and_admins_are_notified(): void
    {
        Event::fake([TaskEscalated::class]);
        $task = $this->create('normal');

        $this->travel(47)->hours();
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();
        $this->assertSame(TaskPriority::Normal, $task->refresh()->priority, 'not due yet');

        $this->travel(61)->minutes();
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();

        $task->refresh();
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame(1, $task->escalation_count);
        $this->assertNotNull($task->escalated_at);
        $this->assertNull($task->overdue_at);
        $this->assertEqualsWithDelta(now()->addHours(6)->timestamp, $task->due_at->timestamp, 2, 'new due time for high');
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.escalated', 'subject_id' => $task->id]);
        Event::assertDispatched(TaskEscalated::class, fn ($e) => $e->payload['task']['priority'] === 'high'
            && $e->broadcastAs() === 'task.escalated'
            && collect($e->broadcastOn())->contains(fn ($c) => $c->name === 'private-company.'.$this->park->company_id));

        // Running again before the new due time changes nothing.
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();
        $this->assertSame(1, $task->refresh()->escalation_count);

        $this->asUser($this->admin)->getJson("/api/admin/tasks/{$task->id}")
            ->assertJsonPath('data.escalation_count', 1)
            ->assertJsonPath('data.overdue', false);
    }

    public function test_an_urgent_task_stays_urgent_and_is_flagged_overdue_once(): void
    {
        Event::fake([TaskEscalated::class]);
        $task = $this->create('urgent');

        $this->travel(61)->minutes();
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();

        $task->refresh();
        $this->assertSame(TaskPriority::Urgent, $task->priority);
        $this->assertNotNull($task->overdue_at);
        $this->assertTrue($task->isOverdue());
        $this->assertSame(1, $task->escalation_count);
        Event::assertDispatchedTimes(TaskEscalated::class, 1);

        $this->asUser($this->admin)->getJson('/api/admin/tasks?status=overdue')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $task->id)
            ->assertJsonPath('data.0.overdue', true);
    }

    public function test_in_progress_tasks_are_not_escalated(): void
    {
        $task = $this->create('high');
        $task->update(['status' => TaskStatus::InProgress, 'started_at' => now()]);

        $this->travel(7)->hours();
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();

        $this->assertSame(TaskPriority::High, $task->refresh()->priority);
        $this->assertSame(0, $task->escalation_count);
        $this->assertFalse(app(TaskService::class)->escalate($task));
    }

    public function test_a_priority_change_recomputes_due_at_from_now(): void
    {
        $task = $this->create('low');
        $this->travel(2)->days();

        $this->asUser($this->admin)->patchJson("/api/admin/tasks/{$task->id}", ['priority' => 'high'])->assertOk()
            ->assertJsonPath('data.priority', 'high');

        $this->assertEqualsWithDelta(now()->addHours(6)->timestamp, $task->refresh()->due_at->timestamp, 2);
    }

    public function test_the_escalate_scenario_sets_the_due_time_of_the_newest_open_task_to_now(): void
    {
        config(['namaa.demo_mode' => true]);
        $simPark = Park::factory()->simulated()->create(['company_id' => $this->park->company_id]);
        Node::factory()->simulated()->create(['park_id' => $simPark->id, 'name' => 'Sim 1']);
        $older = $this->create('high');
        $this->travel(1)->seconds();
        $newest = $this->create('high');
        Task::factory()->inProgress()->create(['park_id' => $this->park->id]); // started: never picked

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'escalate'])->assertOk()
            ->assertJsonPath('data.task_id', $newest->id);
        $this->travel(1)->seconds();
        $this->artisan('namaa:escalate-tasks')->assertSuccessful();

        $this->assertSame(TaskPriority::Urgent, $newest->refresh()->priority);
        $this->assertSame(TaskPriority::High, $older->refresh()->priority);
    }
}
