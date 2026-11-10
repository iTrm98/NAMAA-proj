<?php

namespace Tests\Feature\Tasks;

use App\Enums\AlertType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 3, A2: a dry / heat-stress task shows "Water until X %" with
 * X = (moisture_min + moisture_max) / 2. Done below X is allowed and recorded.
 */
class WateringTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->worker = User::factory()->worker()->create(['company_id' => $company->id]);
        $this->park = Park::factory()->create(['company_id' => $company->id, 'moisture_min' => 25, 'moisture_max' => 70]);
        $this->node = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'last_moisture_pct' => 31.0, 'last_seen_at' => now()]);
    }

    private function task(AlertType $type): Task
    {
        $alert = Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->node->id, 'type' => $type]);

        return Task::factory()->inProgress()->create([
            'park_id' => $this->park->id, 'alert_id' => $alert->id, 'assigned_to' => $this->worker->id,
        ]);
    }

    public function test_dry_and_heat_tasks_have_the_middle_of_the_range_as_target(): void
    {
        foreach ([AlertType::Dry, AlertType::HeatStress] as $type) {
            $task = $this->task($type);
            $this->asUser($this->worker)->getJson("/api/worker/tasks/{$task->id}")
                ->assertOk()->assertJsonPath('data.watering_target_pct', 47.5);
        }

        $leak = $this->task(AlertType::Leak);
        $this->asUser($this->worker)->getJson("/api/worker/tasks/{$leak->id}")->assertJsonPath('data.watering_target_pct', null);
    }

    public function test_done_below_the_target_is_allowed_and_recorded(): void
    {
        $task = $this->task(AlertType::Dry);

        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$task->id}/done", ['note' => 'I know this bed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.done_below_target', true)
            ->assertJsonPath('data.done_moisture_pct', 31);

        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertTrue($task->done_below_target);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.done', 'subject_id' => $task->id, 'diff->after->done_below_target' => true]);
    }

    public function test_done_at_the_target_is_not_flagged(): void
    {
        $this->node->update(['last_moisture_pct' => 48.0]);
        $task = $this->task(AlertType::Dry);

        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$task->id}/done")->assertOk()->assertJsonPath('data.done_below_target', false);
    }

    public function test_other_tasks_have_no_target_flag(): void
    {
        $task = $this->task(AlertType::Leak);

        $this->asUser($this->worker)->postJson("/api/worker/tasks/{$task->id}/done")->assertOk()->assertJsonPath('data.done_below_target', null);
    }
}
