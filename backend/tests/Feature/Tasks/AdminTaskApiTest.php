<?php

namespace Tests\Feature\Tasks;

use App\Enums\AlertType;
use App\Enums\TaskStatus;
use App\Events\TaskCreated;
use App\Events\TaskUpdated;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminTaskApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $ahmed;

    private User $khalid;

    private Park $park;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->ahmed = User::factory()->worker()->create(['company_id' => $this->company->id, 'name' => 'Ahmed']);
        $this->khalid = User::factory()->worker()->create(['company_id' => $this->company->id, 'name' => 'Khalid']);
        $this->park = Park::factory()->create(['company_id' => $this->company->id]);
        $node = Node::factory()->withPump()->create(['park_id' => $this->park->id]);
        $this->alert = Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $node->id, 'type' => AlertType::Dry]);
    }

    private function asAdmin(?User $user = null): static
    {
        return $this->asUser($user ?? $this->admin);
    }

    private function otherCompanyAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_task_park_carries_the_site_type(): void
    {
        $task = Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $this->alert->id]);

        $this->asAdmin()->getJson("/api/admin/tasks/{$task->id}")
            ->assertOk()->assertJsonPath('data.park.site_type', 'park');

        $this->park->update(['site_type' => \App\Enums\SiteType::DemoStand]);
        $this->asAdmin()->getJson("/api/admin/tasks/{$task->id}")
            ->assertOk()->assertJsonPath('data.park.site_type', 'demo_stand');
    }

    public function test_admin_creates_a_task_from_an_alert(): void
    {
        Event::fake([TaskCreated::class]);

        $response = $this->asAdmin()->postJson('/api/admin/tasks', [
            'alert_id' => $this->alert->id, 'assigned_to' => $this->ahmed->id, 'title' => 'Irrigate the park', 'note' => 'Check drippers first',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.park_id', $this->park->id)
            ->assertJsonPath('data.alert_id', $this->alert->id)
            ->assertJsonPath('data.priority', 'high') // dry -> high (decision table)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.assigned_to.name', 'Ahmed')
            ->assertJsonPath('data.created_by.id', $this->admin->id)
            ->assertJsonPath('data.note', 'Check drippers first')
            ->assertJsonPath('data.alert.type', 'dry')
            ->assertJsonPath('data.verified', null);

        $task = Task::sole();
        $this->assertSame($this->company->id, $task->company_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.created', 'subject_id' => $task->id, 'user_id' => $this->admin->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.assigned', 'subject_id' => $task->id]);
        Event::assertDispatched(TaskCreated::class, function (TaskCreated $e) {
            $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->broadcastAs() === 'task.created'
                && in_array('private-user.'.$this->ahmed->id, $channels, true)
                && in_array('private-company.'.$this->company->id, $channels, true)
                && in_array('private-municipality', $channels, true);
        });
    }

    public function test_priority_defaults_follow_the_alert_type_and_can_be_overridden(): void
    {
        $leak = Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Leak]);

        $this->asAdmin()->postJson('/api/admin/tasks', ['alert_id' => $leak->id, 'title' => 'Fix the leak'])
            ->assertCreated()->assertJsonPath('data.priority', 'urgent');

        $this->asAdmin()->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'Weekly check'])
            ->assertCreated()->assertJsonPath('data.priority', 'normal')->assertJsonPath('data.alert_id', null);

        $this->asAdmin()->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'Prune', 'priority' => 'low'])
            ->assertCreated()->assertJsonPath('data.priority', 'low');
    }

    public function test_validation(): void
    {
        $other = $this->otherCompanyAdmin();
        $otherWorker = User::factory()->worker()->create(['company_id' => $other->company_id]);
        $otherPark = Park::factory()->create(['company_id' => $other->company_id]);

        $this->asAdmin()->postJson('/api/admin/tasks', [])->assertStatus(422)->assertJsonValidationErrors(['park_id', 'title']);
        $this->asAdmin()->postJson('/api/admin/tasks', ['park_id' => $otherPark->id, 'title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('park_id');
        $this->asAdmin()->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'x', 'assigned_to' => $otherWorker->id])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->asAdmin()->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'x', 'assigned_to' => $this->admin->id])
            ->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->asAdmin()->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'x', 'priority' => 'asap'])
            ->assertStatus(422)->assertJsonValidationErrors('priority');

        $resolved = Alert::factory()->resolved()->create(['park_id' => $this->park->id]);
        $this->asAdmin()->postJson('/api/admin/tasks', ['alert_id' => $resolved->id, 'title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('alert_id');

        $foreignAlert = Alert::factory()->create(['park_id' => $otherPark->id]);
        $this->asAdmin()->postJson('/api/admin/tasks', ['alert_id' => $foreignAlert->id, 'title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('alert_id');

        $this->asAdmin()->postJson('/api/admin/tasks', ['alert_id' => $this->alert->id, 'title' => 'first'])->assertCreated();
        $this->asAdmin()->postJson('/api/admin/tasks', ['alert_id' => $this->alert->id, 'title' => 'second'])
            ->assertStatus(422)->assertJsonValidationErrors('alert_id');
    }

    public function test_wrong_role_is_403(): void
    {
        $this->asUser($this->ahmed)
            ->postJson('/api/admin/tasks', ['park_id' => $this->park->id, 'title' => 'x'])->assertForbidden();
        $this->asUser(User::factory()->municipality()->create())
            ->getJson('/api/admin/tasks')->assertForbidden();
    }

    public function test_list_filters_and_is_scoped_without_n_plus_one(): void
    {
        Task::factory()->count(3)->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id, 'alert_id' => $this->alert->id, 'created_by' => $this->admin->id]);
        Task::factory()->done()->create(['park_id' => $this->park->id]);
        Task::factory()->create(['park_id' => Park::factory()->create()->id]); // other company

        DB::enableQueryLog();
        $response = $this->asAdmin()->getJson('/api/admin/tasks')->assertOk();
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));

        $response->assertJsonCount(4, 'data')->assertJsonPath('meta.total', 4);
        $this->asAdmin()->getJson('/api/admin/tasks?status=done')->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson('/api/admin/tasks?park_id='.$this->park->id.'&status=open')->assertJsonCount(3, 'data');
        $this->asAdmin()->getJson('/api/admin/tasks?status=bogus')->assertStatus(422);
    }

    public function test_sort_by_priority_puts_urgent_active_tasks_first_then_oldest(): void
    {
        $normalOld = Task::factory()->create(['park_id' => $this->park->id, 'priority' => 'normal', 'created_at' => now()->subHours(3)]);
        $urgentNew = Task::factory()->create(['park_id' => $this->park->id, 'priority' => 'urgent', 'created_at' => now()->subHour()]);
        $urgentOld = Task::factory()->create(['park_id' => $this->park->id, 'priority' => 'urgent', 'created_at' => now()->subHours(2)]);
        $doneUrgent = Task::factory()->done()->create(['park_id' => $this->park->id, 'priority' => 'urgent', 'created_at' => now()->subHours(5)]);

        $ids = collect($this->asAdmin()->getJson('/api/admin/tasks?sort=priority')->json('data'))->pluck('id')->all();

        $this->assertSame([$urgentOld->id, $urgentNew->id, $normalOld->id, $doneUrgent->id], $ids);
    }

    public function test_show_and_other_company_is_404(): void
    {
        $task = Task::factory()->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id]);

        $this->asAdmin()->getJson("/api/admin/tasks/{$task->id}")->assertOk()->assertJsonPath('data.park.id', $this->park->id);
        $this->asAdmin($this->otherCompanyAdmin())->getJson("/api/admin/tasks/{$task->id}")->assertNotFound();
        $this->asAdmin($this->otherCompanyAdmin())->patchJson("/api/admin/tasks/{$task->id}", ['priority' => 'low'])->assertNotFound();
    }

    public function test_reassign_change_priority_and_cancel(): void
    {
        Event::fake([TaskUpdated::class]);
        $task = Task::factory()->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id]);

        $this->asAdmin()->patchJson("/api/admin/tasks/{$task->id}", ['assigned_to' => $this->khalid->id, 'priority' => 'urgent'])
            ->assertOk()->assertJsonPath('data.assigned_to.name', 'Khalid')->assertJsonPath('data.priority', 'urgent');

        $this->assertDatabaseHas('activity_logs', ['action' => 'task.assigned', 'subject_id' => $task->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.updated', 'subject_id' => $task->id]);
        Event::assertDispatched(TaskUpdated::class, function (TaskUpdated $e) {
            $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return in_array('private-user.'.$this->ahmed->id, $channels, true) && in_array('private-user.'.$this->khalid->id, $channels, true);
        });

        $this->asAdmin()->patchJson("/api/admin/tasks/{$task->id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.cancelled', 'subject_id' => $task->id]);

        // A cancelled (or done) task cannot be changed any more.
        $this->asAdmin()->patchJson("/api/admin/tasks/{$task->id}", ['priority' => 'low'])->assertStatus(422);
        $done = Task::factory()->done()->create(['park_id' => $this->park->id]);
        $this->asAdmin()->patchJson("/api/admin/tasks/{$done->id}", ['status' => 'cancelled'])->assertStatus(422);
        $this->asAdmin()->patchJson("/api/admin/tasks/{$task->id}", ['status' => 'done'])->assertStatus(422);
    }

    public function test_workers_list_with_open_task_counts(): void
    {
        Task::factory()->count(2)->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id]);
        Task::factory()->inProgress()->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id]);
        Task::factory()->done()->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id]);
        User::factory()->worker()->create(); // other company

        $this->asAdmin()->getJson('/api/admin/workers')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ahmed')
            ->assertJsonPath('data.0.open_tasks', 3)
            ->assertJsonPath('data.0.in_progress_tasks', 1)
            ->assertJsonPath('data.1.open_tasks', 0);
    }

    public function test_alerts_list_sorted_by_priority_with_category_and_the_task_assignee(): void
    {
        $normal = Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Offline, 'severity' => 'warning', 'created_at' => now()->subHours(5)]);
        $urgent = Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::Leak, 'created_at' => now()->subMinute()]);
        Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $this->alert->id, 'assigned_to' => $this->ahmed->id]);
        Alert::factory()->create(['park_id' => Park::factory()->create()->id]); // other company

        $response = $this->asAdmin()->getJson('/api/admin/alerts')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $urgent->id)
            ->assertJsonPath('data.0.priority', 'urgent')
            ->assertJsonPath('data.0.category', 'water')
            ->assertJsonPath('data.1.id', $this->alert->id)
            ->assertJsonPath('data.1.priority', 'high')
            ->assertJsonPath('data.1.category', 'plant')
            ->assertJsonPath('data.1.task_ready', true)
            ->assertJsonPath('data.1.task.assigned_to.name', 'Ahmed')
            ->assertJsonPath('data.2.id', $normal->id)
            ->assertJsonPath('data.2.category', 'devices')
            ->assertJsonPath('data.2.task', null);

        $this->assertArrayNotHasKey('severity', $response->json('data.0'), 'severity stays internal');
    }

    public function test_park_detail_has_open_tasks_and_the_pump_card(): void
    {
        Task::factory()->create(['park_id' => $this->park->id, 'assigned_to' => $this->ahmed->id, 'title' => 'Open one']);
        Task::factory()->done()->create(['park_id' => $this->park->id]);

        $this->asAdmin()->getJson("/api/admin/parks/{$this->park->id}")->assertOk()
            ->assertJsonCount(1, 'data.open_tasks')
            ->assertJsonPath('data.open_tasks.0.title', 'Open one')
            ->assertJsonPath('data.open_tasks.0.assigned_to.name', 'Ahmed')
            ->assertJsonPath('data.pump.target', false)
            ->assertJsonPath('data.auto_irrigation', false)
            ->assertJsonPath('data.has_pump', true);
    }

    public function test_cancelled_status_is_kept_by_the_enum(): void
    {
        $this->assertSame('cancelled', TaskStatus::Cancelled->value);
        $this->assertFalse(TaskStatus::Cancelled->isActive());
    }
}
