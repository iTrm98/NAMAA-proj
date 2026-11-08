<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\PumpReason;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\NdviReading;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_on_sqlite_not_the_real_database(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        // Docker sets APP_ENV=local; phpunit.xml must force "testing" (runningUnitTests() relies on it).
        $this->assertSame('testing', app()->environment());
        $this->assertNotSame('reverb', config('broadcasting.default')); // "null" in phpunit.xml
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_full_data_model_relations_and_casts(): void
    {
        // Company, users
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();
        $worker = User::factory()->worker()->for($company)->create();
        $municipality = User::factory()->municipality()->create();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($worker->isWorker());
        $this->assertTrue($municipality->isMunicipality());
        $this->assertNull($municipality->company_id);
        $this->assertCount(2, $company->users);

        // Park with a pump sensor node and a camera node
        $park = Park::factory()->for($company)->create();
        $sensor = Node::factory()->withPump()->for($park)->create(['name' => 'Model A']);
        $camera = Node::factory()->camera()->for($park)->create();

        $this->assertSame(ParkStatus::Offline, $park->status);
        $this->assertSame(DataSource::Real, $park->source);
        $this->assertSame('Polygon', $park->polygon['type']);
        $this->assertTrue($park->sensorNode->is($sensor));
        $this->assertTrue($park->cameraNode->is($camera));
        $this->assertTrue($park->pumpNode->is($sensor));
        $this->assertSame(NodeType::Camera, $camera->type);
        $this->assertCount(1, Park::forCompany($company->id)->get());

        // Readings (one real, one simulated) and a pump log
        Reading::factory()->for($sensor)->pumping()->create();
        Reading::factory()->for($sensor)->simulated()->create();
        $reading = $sensor->readings()->first();

        $this->assertSame($park->id, $reading->park_id);
        $this->assertTrue($reading->park->is($park));
        $this->assertTrue($reading->pump_on);
        $this->assertIsFloat($reading->moisture_pct);
        $this->assertCount(2, $park->readings);
        $this->assertSame(1, $park->readings()->where('source', DataSource::Simulated)->count());

        $log = PumpLog::factory()->for($sensor)->create([
            'user_id' => $admin->id, 'target' => true, 'reason' => PumpReason::Manual,
        ]);
        $this->assertSame(PumpReason::Manual, $log->fresh()->reason);
        $this->assertTrue($log->user->is($admin));
        $this->assertCount(1, $sensor->pumpLogs);

        // Alert -> task assigned to the worker
        $alert = Alert::factory()->for($park)->create(['node_id' => $sensor->id]);
        $task = Task::factory()->for($park)->create([
            'alert_id' => $alert->id,
            'assigned_to' => $worker->id,
            'created_by' => $admin->id,
            'priority' => TaskPriority::High,
        ]);
        $task->refresh();

        $this->assertSame(AlertType::Dry, $alert->type);
        $this->assertSame(AlertSeverity::Critical, $alert->severity);
        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
        $this->assertSame(25, $alert->data['moisture_min']);
        $this->assertCount(1, Alert::open()->get());
        $this->assertTrue($alert->node->is($sensor));

        $this->assertSame($company->id, $task->company_id);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertTrue($task->alert->is($alert));
        $this->assertTrue($task->assignee->is($worker));
        $this->assertTrue($task->creator->is($admin));
        $this->assertCount(1, $alert->tasks);
        $this->assertCount(1, $worker->assignedTasks);
        $this->assertCount(1, $admin->createdTasks);
        $this->assertCount(1, Task::forCompany($company->id)->forWorker($worker->id)->status(TaskStatus::Open)->get());
        $this->assertCount(0, Task::status('done')->get());

        // Detection, NDVI, activity log
        $detection = Detection::factory()->for($park)->create(['node_id' => $camera->id]);
        $this->assertIsArray($detection->boxes);
        $this->assertCount($detection->count, $detection->boxes);
        $this->assertTrue($detection->node->is($camera));
        $this->assertCount(1, $park->detections);

        $ndvi = NdviReading::factory()->for($park)->create(['date' => '2026-11-05', 'mean' => 0.42]);
        $this->assertSame('2026-11-05', $ndvi->fresh()->date->toDateString());
        $this->assertSame(0.42, $ndvi->fresh()->mean);
        $this->assertCount(1, $park->ndviReadings);

        $activity = ActivityLog::factory()->create([
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'action' => 'task.assigned',
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'diff' => ['before' => ['assigned_to' => null], 'after' => ['assigned_to' => $worker->id]],
        ]);
        $activity->refresh();
        $this->assertTrue($activity->subject->is($task));
        $this->assertTrue($activity->company->is($company));
        $this->assertSame($worker->id, $activity->diff['after']['assigned_to']);

        // Delete rules: removing the worker keeps the task (assigned_to set null)
        $worker->delete();
        $this->assertNull($task->fresh()->assigned_to);
    }

    public function test_simulated_readings_older_than_30_days_are_prunable(): void
    {
        $node = Node::factory()->create();
        Reading::factory()->for($node)->simulated()->create(['created_at' => now()->subDays(31)]);
        Reading::factory()->for($node)->create(['created_at' => now()->subDays(31)]);
        Reading::factory()->for($node)->simulated()->create();

        $this->assertSame(1, (new Reading)->prunable()->count());
    }
}
