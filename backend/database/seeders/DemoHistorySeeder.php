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
 * History for the three SIMULATED parks only: 7 days of readings, the demo
 * alerts and tasks, activity logs and 90 days of NDVI. Real parks get nothing:
 * their history must come from the boards and the satellite job.
 *
 * - Al Adel:     drying out -> status dry, OPEN dry alert, OPEN unassigned task.
 * - Alkhatateen: healthy; RESOLVED leak 3 days ago, DONE + verified task (Ahmed, 42 min).
 * - Alhilal:     healthy; RESOLVED dry alert 5 days ago, DONE + verified task (Khalid, 65 min).
 */
class DemoHistorySeeder extends Seeder
{
    private const SCENARIOS = [
        'al_adel' => 'drying_out',
        'alkhatateen' => 'leak',
        'alhilal' => 'dry_fixed',
    ];

    private User $admin;

    private User $ahmed;

    private User $khalid;

    public function run(): void
    {
        $this->admin = User::where('email', DemoAccountsSeeder::ADMIN_EMAIL)->firstOrFail();
        $this->ahmed = User::where('email', DemoAccountsSeeder::WORKER1_EMAIL)->firstOrFail();
        $this->khalid = User::where('email', DemoAccountsSeeder::WORKER2_EMAIL)->firstOrFail();

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
                    'alkhatateen' => $this->leakHistory($park, $node, $result),
                    'alhilal' => $this->dryHistory($park, $node, $result),
                };

                $this->cacheLatest($park, $node, $result['rows'], $statusChangedAt);
                $this->ndvi($park, $key, $end);

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

        $this->doneTask($park, $alert, $this->ahmed, 'Fix leak at Alkhatateen Park', TaskPriority::Urgent, $e, $result['rows'], [
            'created' => 4, 'assigned' => 7, 'started' => 15,
        ], 'Replaced a cracked fitting on the main line near the east gate. Ran the pump for a short test: no flow with the pump off.');

        return CarbonImmutable::parse($result['rows'][0]['created_at']);
    }

    /** Resolved dry alert 5 days ago, fixed and verified by Khalid in 65 minutes. */
    private function dryHistory(Park $park, Node $node, array $result): CarbonImmutable
    {
        $e = $result['events'];

        $alert = $this->alert($park, $node, AlertType::Dry, AlertSeverity::Critical, $e['alert_at'], $e['resolved_at'], [
            'moisture_pct' => ReadingSimulator::valueAt($result['rows'], $e['alert_at'], 'moisture_pct'),
            'moisture_min' => $park->moisture_min,
            'readings' => 3,
        ]);

        $this->doneTask($park, $alert, $this->khalid, 'Irrigate Alhilal Park', TaskPriority::High, $e, $result['rows'], [
            'created' => 5, 'assigned' => 9, 'started' => 30,
        ], 'Cleared blocked drippers on the north beds and watered the area with the pump.');

        return $e['resolved_at']; // dry -> ok
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
            'note' => $note,
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

    /**
     * One NDVI value every 5 days for the last 90 days. Healthy parks 0.45-0.55;
     * Al Adel falls from about 0.40 to 0.18. Pixels: 10 m x 10 m, some lost to clouds.
     */
    private function ndvi(Park $park, string $key, CarbonImmutable $end): void
    {
        $random = new Randomizer(new Mt19937(crc32('ndvi-'.$key)));
        $count = 18; // newest 2 days ago, oldest 87 days ago
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $progress = $i / ($count - 1); // 0 = oldest, 1 = newest
            $mean = $key === 'al_adel'
                ? 0.40 - 0.22 * $progress + $random->getFloat(-0.01, 0.01)
                : 0.50 + $random->getFloat(-0.04, 0.04);

            $rows[] = [
                'park_id' => $park->id,
                'date' => $end->subDays(2 + 5 * ($count - 1 - $i))->toDateString(),
                'mean' => round($mean, 3),
                'pixels' => (int) round($park->area_m2 / 100 * $random->getFloat(0.80, 0.95)),
                'created_at' => $end,
                'updated_at' => $end,
            ];
        }

        DB::table('ndvi_readings')->insert($rows);
    }
}
