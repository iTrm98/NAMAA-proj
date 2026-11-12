<?php

namespace Tests\Feature\Overview;

use App\Enums\AlertType;
use App\Enums\PumpReason;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6, B3: GET /api/admin/parks/{park}/timeline. */
class TimelineTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $action, $subject, array $diff, ?User $user, $at): void
    {
        ActivityLog::create([
            'company_id' => $user?->company_id ?? $subject->company_id ?? null, 'user_id' => $user?->id, 'action' => $action,
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id, 'diff' => $diff, 'created_at' => $at,
        ]);
    }

    public function test_the_chain_from_alert_to_verified_fix_is_ordered_with_times_and_duration(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Demo Admin', 'name_ar' => 'مدير العرض']);
        $worker = User::factory()->worker()->create(['company_id' => $admin->company_id, 'name' => 'Ahmed', 'name_ar' => 'أحمد']);
        $park = Park::factory()->create(['company_id' => $admin->company_id]);
        $pumpNode = Node::factory()->withPump()->create(['park_id' => $park->id]);
        $t0 = now()->subHours(2)->startOfSecond();

        Alert::factory()->resolved()->create(['park_id' => $park->id, 'node_id' => $pumpNode->id, 'type' => AlertType::Leak, 'created_at' => $t0->copy()->subDays(5)]); // older problem
        $alert = Alert::factory()->resolved()->create(['park_id' => $park->id, 'node_id' => $pumpNode->id, 'type' => AlertType::Dry, 'created_at' => $t0]);
        $task = Task::factory()->done()->create([
            'park_id' => $park->id, 'alert_id' => $alert->id, 'assigned_to' => $worker->id, 'created_by' => $admin->id,
            'started_at' => $t0->copy()->addMinutes(20), 'done_at' => $t0->copy()->addMinutes(50), 'verified' => true,
            'verified_at' => $t0->copy()->addMinutes(60), 'time_to_resolve_min' => 60, 'title_key' => 'taskTitle.dry',
        ]);

        $this->log('alert.raised', $alert, ['after' => ['type' => 'dry', 'priority' => 'high']], null, $t0);
        $this->log('task.created', $task, ['after' => ['priority' => 'high']], $admin, $t0->copy()->addMinutes(5));
        $this->log('task.assigned', $task, ['after' => ['assigned_to' => $worker->id]], $admin, $t0->copy()->addMinutes(6));
        $this->log('task.started', $task, [], $worker, $t0->copy()->addMinutes(20));
        $this->log('task.done', $task, ['after' => ['note' => 'Valve was closed', 'moisture_pct' => 48.0, 'done_below_target' => false]], $worker, $t0->copy()->addMinutes(50));
        $this->log('task.verified', $task, ['after' => ['verified' => true, 'time_to_resolve_min' => 60]], null, $t0->copy()->addMinutes(60));
        $this->log('alert.resolved', $alert, ['after' => ['reason' => 'task_verified']], null, $t0->copy()->addMinutes(60));
        PumpLog::create(['node_id' => $pumpNode->id, 'user_id' => $worker->id, 'target' => true, 'reason' => PumpReason::Manual, 'created_at' => $t0->copy()->addMinutes(25)]);
        PumpLog::create(['node_id' => $pumpNode->id, 'user_id' => null, 'target' => false, 'reason' => PumpReason::Rule, 'created_at' => $t0->copy()->addMinutes(40)]);
        PumpLog::create(['node_id' => $pumpNode->id, 'user_id' => null, 'target' => true, 'reason' => PumpReason::Rule, 'created_at' => $t0->copy()->subMinutes(30)]); // before the task started

        $data = $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline")->assertOk()->json('data');

        $this->assertSame($alert->id, $data['alert']['id']);
        $this->assertSame(
            ['alert_raised', 'task_created', 'task_assigned', 'task_started', 'pump_on', 'pump_off', 'task_done', 'verified'],
            array_column($data['steps'], 'key'),
        );
        $this->assertSame('أحمد', $data['steps'][2]['data']['assigned_to']['name_ar']);
        $this->assertSame('Valve was closed', $data['steps'][6]['data']['note']);
        $this->assertSame('manual', $data['steps'][4]['data']['reason']);
        $this->assertSame($t0->utc()->format('Y-m-d\TH:i:s\Z'), $data['steps'][0]['at']);
        $this->assertSame(60, $data['duration_minutes']);
        $this->assertSame('taskTitle.dry', $data['task']['title_key']);
    }

    public function test_an_unverified_fix_and_a_bare_alert(): void
    {
        $admin = User::factory()->admin()->create();
        $park = Park::factory()->create(['company_id' => $admin->company_id]);

        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline")->assertOk()->assertJsonPath('data', null);

        $alert = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry]);
        $task = Task::factory()->done()->create(['park_id' => $park->id, 'alert_id' => $alert->id, 'verified' => false]);
        $this->log('alert.raised', $alert, ['after' => ['type' => 'dry', 'priority' => 'high']], null, now()->subHour());
        $this->log('task.verified', $task, ['after' => ['verified' => false, 'reason' => 'no_data']], null, now());

        $data = $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline")->json('data');

        $this->assertSame(['alert_raised', 'not_verified'], array_column($data['steps'], 'key'));
        $this->assertSame('no_data', $data['steps'][1]['data']['reason']);
        $this->assertNull($data['duration_minutes']);
    }

    public function test_alert_id_picks_an_older_problem(): void
    {
        $admin = User::factory()->admin()->create();
        $park = Park::factory()->create(['company_id' => $admin->company_id]);
        $old = Alert::factory()->resolved()->create(['park_id' => $park->id, 'type' => AlertType::Leak, 'created_at' => now()->subDays(3)]);
        Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry]);

        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline?alert_id={$old->id}")
            ->assertOk()->assertJsonPath('data.alert.type', 'leak');
        $foreign = Alert::factory()->create();
        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline?alert_id={$foreign->id}")->assertNotFound();
    }

    public function test_default_alert_prefers_one_with_a_task_and_skips_awaiting_install(): void
    {
        $admin = User::factory()->admin()->create();
        $park = Park::factory()->create(['company_id' => $admin->company_id]);
        $withTask = Alert::factory()->resolved()->create(['park_id' => $park->id, 'type' => AlertType::Leak, 'created_at' => now()->subDays(2)]);
        Task::factory()->create(['park_id' => $park->id, 'alert_id' => $withTask->id]);
        Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry, 'created_at' => now()->subDay()]);
        $cleaned = Alert::factory()->resolved()->create(['park_id' => $park->id, 'type' => AlertType::Offline, 'created_at' => now()]);
        $this->log('alert.resolved', $cleaned, ['after' => ['reason' => 'awaiting_install']], null, now());

        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline")->assertOk()->assertJsonPath('data.alert.id', $withTask->id);

        Task::where('alert_id', $withTask->id)->delete();
        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline")->assertOk()->assertJsonPath('data.alert.type', 'dry');
    }

    public function test_access_rules(): void
    {
        $admin = User::factory()->admin()->create();
        $park = Park::factory()->create(['company_id' => $admin->company_id]);
        $foreignPark = Park::factory()->create();

        $this->getJson("/api/admin/parks/{$park->id}/timeline")->assertUnauthorized();
        $this->asUser($admin)->getJson("/api/admin/parks/{$foreignPark->id}/timeline")->assertNotFound();
        $this->asUser(User::factory()->worker()->create(['company_id' => $admin->company_id]))->getJson("/api/admin/parks/{$park->id}/timeline")->assertForbidden();
        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}/timeline?alert_id=abc")->assertUnprocessable();

        $viewer = User::factory()->municipality()->create();
        $this->asUser($viewer)->getJson("/api/municipality/parks/{$foreignPark->id}/timeline")->assertOk();
        $test = Park::factory()->create(['is_test' => true]);
        $this->asUser($viewer)->getJson("/api/municipality/parks/{$test->id}/timeline")->assertNotFound();
    }
}
