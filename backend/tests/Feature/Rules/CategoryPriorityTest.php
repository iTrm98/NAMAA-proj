<?php

namespace Tests\Feature\Rules;

use App\Enums\AlertCategory;
use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Events\AlertUpdated;
use App\Events\TaskUpdated;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Sprint 2.5, phase A: every alert = a category (from the type) + a stored priority. */
class CategoryPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_alert_type_maps_to_its_category(): void
    {
        $expected = [
            'leak' => 'water', 'no_flow' => 'water', 'blockage' => 'water', 'over' => 'water',
            'dry' => 'plant', 'heat_stress' => 'plant', 'ndvi_drop' => 'plant',
            'litter' => 'cleanliness',
            'offline' => 'devices', 'sensor_fault' => 'devices', 'low_battery' => 'devices',
        ];

        $this->assertEqualsCanonicalizing(array_keys($expected), array_column(AlertType::cases(), 'value'), 'every type is mapped');

        foreach ($expected as $type => $category) {
            $this->assertSame($category, AlertCategory::fromType(AlertType::from($type))->value, $type);
            $this->assertSame($category, AlertType::from($type)->category()->value, $type);
        }
    }

    public function test_base_priority_per_type(): void
    {
        $expected = [
            'urgent' => ['leak', 'no_flow', 'heat_stress'],
            'high' => ['dry', 'blockage'],
            'normal' => ['over', 'litter', 'offline', 'sensor_fault'],
            'low' => ['ndvi_drop', 'low_battery'],
        ];

        foreach ($expected as $priority => $types) {
            foreach ($types as $type) {
                $this->assertSame($priority, AlertType::from($type)->priority()->value, $type);
            }
        }
    }

    public function test_the_migration_backfills_the_priority_of_existing_alerts(): void
    {
        $park = Park::factory()->create();
        $migration = require database_path('migrations/2026_11_11_000001_add_priority_to_alerts_table.php');
        $migration->down();

        $row = fn (string $type, string $status) => DB::table('alerts')->insertGetId([
            'park_id' => $park->id, 'type' => $type, 'severity' => 'warning', 'status' => $status,
            'data' => '{}', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);
        $ids = [
            'leak' => $row('leak', 'resolved'),
            'dry' => $row('dry', 'open'),
            'over' => $row('over', 'open'),
            'ndvi_drop' => $row('ndvi_drop', 'resolved'),
            'offline_open' => $row('offline', 'open'),
            'offline_resolved' => $row('offline', 'resolved'),
        ];

        $migration->up();

        $get = fn (string $key) => DB::table('alerts')->find($ids[$key]);
        $this->assertSame('urgent', $get('leak')->priority);
        $this->assertSame('high', $get('dry')->priority);
        $this->assertSame('normal', $get('over')->priority);
        $this->assertSame('low', $get('ndvi_drop')->priority);
        $this->assertSame('normal', $get('offline_open')->priority);
        $this->assertNotNull($get('dry')->task_ready_at, 'existing alerts could already get a task');
        $this->assertNotNull($get('offline_resolved')->task_ready_at);
        $this->assertNull($get('offline_open')->task_ready_at, 'an open offline alert waits for the offline check');
    }

    public function test_a_task_from_an_alert_takes_the_alert_priority_and_rises_with_it(): void
    {
        Event::fake([AlertUpdated::class, TaskUpdated::class]);
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $park = Park::factory()->create(['company_id' => $company->id]);
        $node = Node::factory()->create(['park_id' => $park->id]);
        $alert = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $node->id, 'type' => AlertType::Offline, 'priority' => TaskPriority::Normal]);

        $taskId = $this->asUser($admin)->postJson('/api/admin/tasks', ['alert_id' => $alert->id, 'title' => 'Restart the node'])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.category', 'devices')
            ->assertJsonPath('data.alert.priority', 'normal')
            ->assertJsonPath('data.alert.category', 'devices')
            ->json('data.id');
        $task = Task::find($taskId);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $task->due_at->timestamp, 2);

        $this->travel(10)->minutes();
        $this->assertTrue(app(AlertService::class)->raisePriority($alert->fresh(), TaskPriority::High, 'blind_on_dry'));

        $task->refresh();
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertEqualsWithDelta(now()->addHours(6)->timestamp, $task->due_at->timestamp, 2, 'a new due time from now');
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.escalated', 'subject_id' => $alert->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.updated', 'subject_id' => $task->id]);
        Event::assertDispatched(AlertUpdated::class, fn ($e) => $e->payload['alert']['priority'] === 'high');
        Event::assertDispatched(TaskUpdated::class, fn ($e) => $e->payload['task']['priority'] === 'high');

        // Never lowered, and a task already above the alert keeps its priority.
        $this->assertFalse(app(AlertService::class)->raisePriority($alert->fresh(), TaskPriority::Normal, 'x'));
        $task->update(['priority' => TaskPriority::Urgent]);
        app(AlertService::class)->raisePriority($alert->fresh(), TaskPriority::Urgent, 'test');
        $this->assertSame(TaskPriority::Urgent, $task->refresh()->priority);
    }

    public function test_resources_expose_category_and_priority_but_not_severity(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $park = Park::factory()->create(['company_id' => $company->id]);
        $alert = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Leak]);
        Task::factory()->create(['park_id' => $park->id, 'alert_id' => $alert->id, 'priority' => TaskPriority::Urgent, 'due_at' => now()->addHour()]);

        $alerts = $this->asUser($admin)->getJson('/api/admin/alerts')->assertOk()
            ->assertJsonPath('data.0.category', 'water')
            ->assertJsonPath('data.0.priority', 'urgent')
            ->assertJsonPath('data.0.task.priority', 'urgent')
            ->assertJsonPath('data.0.task.overdue', false);
        $this->assertArrayNotHasKey('severity', $alerts->json('data.0'));

        $tasks = $this->getJson('/api/admin/tasks')->assertOk()
            ->assertJsonPath('data.0.category', 'water')
            ->assertJsonPath('data.0.priority', 'urgent')
            ->assertJsonPath('data.0.alert.priority', 'urgent')
            ->assertJsonPath('data.0.overdue', false)
            ->assertJsonPath('data.0.escalation_count', 0);
        $this->assertNotNull($tasks->json('data.0.due_at'));
        $this->assertArrayNotHasKey('severity', $tasks->json('data.0.alert'));

        $this->getJson("/api/admin/parks/{$park->id}")->assertOk()
            ->assertJsonPath('data.open_alerts.0.category', 'water')
            ->assertJsonPath('data.open_alerts.0.priority', 'urgent')
            ->assertJsonPath('data.open_alerts.0.task_ready', true);
    }

    public function test_open_alerts_of_a_park_are_sorted_by_priority_then_oldest(): void
    {
        $park = Park::factory()->create();
        $low = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::LowBattery, 'created_at' => now()->subHours(3)]);
        $urgentNew = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Leak, 'created_at' => now()->subMinute()]);
        $urgentOld = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::NoFlow, 'created_at' => now()->subHour()]);
        $high = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry, 'created_at' => now()->subHours(2)]);

        $this->assertSame([$urgentOld->id, $urgentNew->id, $high->id, $low->id], $park->openAlerts()->pluck('id')->all());
    }
}
