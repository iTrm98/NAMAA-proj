<?php

namespace Database\Seeders;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\ReadingSimulator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * History for the two SIMULATED parks only: 7 days of readings, the demo
 * alerts and tasks, and activity logs (no NDVI is seeded; satellite values come from the fetch job). Real parks get nothing:
 * their history must come from the boards and the satellite job.
 *
 * - Al Adel:     drying out -> status dry, OPEN dry alert, OPEN unassigned task.
 * - Al Aziziyah: healthy; RESOLVED leak 3 days ago, DONE + verified task (Ahmed, 42 min).
 */
class DemoHistorySeeder extends Seeder
{
    private const SCENARIOS = [
        'al_adel' => 'drying_out',
        'al_aziziyah' => 'leak',
    ];

    private User $admin;

    private User $ahmed;

    public function run(): void
    {
        $this->admin = User::where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
        $this->ahmed = User::where('email', DemoAccountsSeeder::WORKER1_EMAIL)->firstOrFail();

        $end = CarbonImmutable::now('UTC')->startOfMinute();
        $end = $end->subMinutes($end->minute % ReadingSimulator::STEP_MIN);

        $keys = collect(DemoParksSeeder::parks())->pluck('key', 'name_en');

        Park::where('source', DataSource::Simulated)->orderBy('id')->get()
            ->each(function (Park $park) use ($keys, $end) {
                $key = $keys[$park->name_en];
                $node = $park->nodes()->where('type', NodeType::Sensor)->firstOrFail();

                $result = (new ReadingSimulator(
                    $key, self::SCENARIOS[$key], $node->id, $park->id,
                    $node->dry_value, $node->wet_value, $park->moisture_min, $end,
                ))->run();

                foreach (array_chunk($result['rows'], 500) as $chunk) {
                    DB::table('readings')->insert($chunk);
                }

                $statusChangedAt = match ($key) {
                    'al_adel' => $this->dryingOut($park, $node, $result, $end),
                    'al_aziziyah' => $this->leakHistory($park, $node, $result),
                };

                $this->cacheLatest($park, $node, $result['rows'], $statusChangedAt);

                $this->command?->info(sprintf('%s: %d readings, status %s.', $park->name_en, count($result['rows']), $park->status->value));
            });
    }

    /** Open dry alert and an open, unassigned task (to be assigned live in the demo). */
    private function dryingOut(Park $park, Node $node, array $result, CarbonImmutable $end): CarbonImmutable
    {
        $alertAt = $result['events']['alert_at'];
        $latest = end($result['rows']);

        $alert = $this->alert($park, $node, AlertType::Dry, AlertSeverity::Critical, $alertAt, null, [
            'moisture_pct' => $latest['moisture_pct'],
            'moisture_min' => $park->moisture_min,
            'readings' => 3,
        ]);

        $createdAt = CarbonImmutable::parse($alertAt)->addMinutes(15)->min($end);
        $task = $this->task($park, $alert, null, 'Irrigate Al Adel Park', TaskPriority::High, $createdAt, [
            'status' => TaskStatus::Open,
        ]);
        $this->log($task, $this->admin, 'task.created', $createdAt, ['after' => $this->createdDiff($task)]);

        return $alertAt;
    }

    /** Resolved leak 3 days ago, fixed and verified by Ahmed in 42 minutes. */
    private function leakHistory(Park $park, Node $node, array $result): CarbonImmutable
    {
        $e = $result['events'];

        $alert = $this->alert($park, $node, AlertType::Leak, AlertSeverity::Critical, $e['alert_at'], $e['resolved_at'], [
            'flow' => $e['leak_flow'],
            'pump_on' => false,
            'readings' => 2,
        ]);

        $this->doneTask($park, $alert, $this->ahmed, 'Fix leak at Al Aziziyah Park', TaskPriority::Urgent, $e, $result['rows'], [
            'created' => 4, 'assigned' => 7, 'started' => 15,
        ], 'Replaced a cracked fitting on the main line near the east gate. Ran the pump for a short test: no flow with the pump off.');

        return CarbonImmutable::parse($result['rows'][0]['created_at']);
    }

    /**
     * A done, verified task with its full activity trail.
     *
     * @param  array{created: int, assigned: int, started: int}  $offsets  minutes after the alert
     */
    private function doneTask(Park $park, Alert $alert, User $worker, string $title, TaskPriority $priority, array $e, array $rows, array $offsets, string $note): void
    {
        $alertAt = $e['alert_at'];
        $createdAt = $alertAt->addMinutes($offsets['created']);
        $assignedAt = $alertAt->addMinutes($offsets['assigned']);
        $startedAt = $alertAt->addMinutes($offsets['started']);
        $doneAt = $e['done_at'];
        $verifiedAt = $doneAt->addMinutes(10); // VerifyTask window (decision-table.md)
        $minutes = (int) $alertAt->diffInMinutes($doneAt);
        $water = ReadingSimulator::waterUsed($rows, $startedAt, $verifiedAt);

        $task = $this->task($park, $alert, $worker, $title, $priority, $createdAt, [
            'status' => TaskStatus::Done,
            'started_at' => $startedAt,
            'done_at' => $doneAt,
            'done_note' => $note,
            'verified' => true,
            'verified_at' => $verifiedAt,
            'time_to_resolve_min' => $minutes,
            'water_used_l' => $water,
            'updated_at' => $verifiedAt,
        ]);

        $this->log($task, $this->admin, 'task.created', $createdAt, ['after' => $this->createdDiff($task)]);
        $this->log($task, $this->admin, 'task.assigned', $assignedAt, ['before' => ['assigned_to' => null], 'after' => ['assigned_to' => $worker->id]]);
        $this->log($task, $worker, 'task.started', $startedAt, ['before' => ['status' => 'open'], 'after' => ['status' => 'in_progress']]);
        $this->log($task, $worker, 'task.done', $doneAt, ['before' => ['status' => 'in_progress'], 'after' => ['status' => 'done']]);
        $this->log($task, null, 'task.verified', $verifiedAt, ['after' => [
            'verified' => true, 'time_to_resolve_min' => $minutes, 'water_used_l' => $water,
        ]]);
    }

    private function alert(Park $park, Node $node, AlertType $type, AlertSeverity $severity, CarbonImmutable $at, ?CarbonImmutable $resolvedAt, array $data): Alert
    {
        $alert = new Alert;
        $alert->forceFill([
            'park_id' => $park->id,
            'node_id' => $node->id,
            'type' => $type,
            'severity' => $severity,
            'status' => $resolvedAt ? AlertStatus::Resolved : AlertStatus::Open,
            'data' => $data,
            'resolved_at' => $resolvedAt,
            'created_at' => $at,
            'updated_at' => $resolvedAt ?? $at,
        ])->save();

        return $alert;
    }

    private function task(Park $park, Alert $alert, ?User $worker, string $title, TaskPriority $priority, CarbonImmutable $createdAt, array $extra): Task
    {
        $task = new Task;
        $task->forceFill([
            'company_id' => $park->company_id,
            'park_id' => $park->id,
            'alert_id' => $alert->id,
            'assigned_to' => $worker?->id,
            'created_by' => $this->admin->id,
            'title' => $title,
            'title_key' => ($key = \App\Support\TaskTitles::keyFor($title, $park->name_en)),
            'title_park_id' => $key ? $park->id : null,
            'priority' => $priority,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ] + $extra)->save();

        return $task;
    }

    private function createdDiff(Task $task): array
    {
        return [
            'title' => $task->title,
            'priority' => $task->priority->value,
            'park_id' => $task->park_id,
            'alert_id' => $task->alert_id,
        ];
    }

    private function log(Task $task, ?User $user, string $action, CarbonImmutable $at, array $diff): void
    {
        (new ActivityLog)->forceFill([
            'company_id' => $task->company_id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'diff' => $diff,
            'ip' => null,
            'created_at' => $at,
        ])->save();
    }

    /** Park status and the node's cached last_* values from the latest reading. */
    private function cacheLatest(Park $park, Node $node, array $rows, CarbonImmutable $statusChangedAt): void
    {
        $latest = end($rows);
        $at = CarbonImmutable::parse($latest['created_at']);
        $openDry = $park->alerts()->where('status', AlertStatus::Open)->where('type', AlertType::Dry)->exists();

        $node->update([
            'last_seen_at' => $at,
            'last_moisture_pct' => $latest['moisture_pct'],
            'last_flow' => $latest['flow'],
            'last_temperature' => $latest['temperature'],
            'last_humidity' => $latest['humidity'],
            'last_pump_on' => $latest['pump_on'],
        ]);

        $park->update([
            'status' => $openDry ? ParkStatus::Dry : ParkStatus::Ok,
            'status_changed_at' => $statusChangedAt,
            'last_reading_at' => $at,
        ]);
    }
}
