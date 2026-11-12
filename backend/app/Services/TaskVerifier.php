<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\NodeType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Services\Rules\RuleContext;
use App\Support\Namaa;
use Illuminate\Support\Facades\DB;

/**
 * The proof of value (decision table, "Task verification"). Pressing Done
 * proves nothing; the data does. A done task is checked again and again
 * (namaa:verify-tasks, every minute / 10 s in demo mode):
 *
 *  - verified when the alert's condition has stayed cleared for a continuous
 *    window of readings after done_at (10 min, 1 min in demo mode). A bad
 *    reading, or a gap longer than the offline window, restarts the window.
 *    Moisture tasks use hysteresis: the window starts only once the soil is
 *    past the limit by the recovery margin (dry: >= min + 5), it is broken only
 *    by a reading past the limit itself (dry: < min); readings in between keep
 *    a running window going but do not start one;
 *  - not verified when the window is not reached within the timeout after
 *    Done (60 min, 3 min in demo mode): reason `condition_returned` when a
 *    reading after Done still showed the problem, else `no_data`. The alert
 *    then stays open.
 *
 * Litter tasks are checked on the camera's frames instead of readings: a
 * frame without litter is good, a frame with litter breaks the window. In demo
 * mode (verify_clean_frames) 3 clean frames in a row verify it instead of the window.
 *
 * verified = true: verified_at, time_to_resolve_min (alert raised -> verified,
 * or task created -> verified) and the alert resolved if still open.
 */
class TaskVerifier
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly AlertService $alerts,
        private readonly ParkStatusService $status,
        private readonly TaskService $tasks,
    ) {}

    /** Check every done task that is still waiting. Returns the number decided. */
    public function run(): int
    {
        $decided = 0;

        foreach (Task::where('status', TaskStatus::Done)->whereNull('verified')->orderBy('done_at')->pluck('id') as $id) {
            $task = Task::find($id);
            if ($task && $this->verify($task) !== null) {
                $decided++;
            }
        }

        return $decided;
    }

    /** Returns true / false once decided, null while still waiting. */
    public function verify(Task $task): ?bool
    {
        return DB::transaction(function () use ($task) {
            $task = Task::lockForUpdate()->find($task->id);

            if (! $task || $task->status !== TaskStatus::Done || $task->verified !== null || $task->done_at === null) {
                return null; // cancelled, not done or already decided
            }

            $task->loadMissing(['alert.node', 'park']);
            $alert = $task->alert;
            $window = Namaa::timing('verify_window_seconds');
            $maxGap = Namaa::timing('offline_seconds');
            $deadline = $task->done_at->copy()->addSeconds(Namaa::timing('verify_timeout_seconds'));

            // [time, state] samples after Done: camera frames for litter, readings otherwise.
            if ($alert?->type?->isLitter()) {
                $maxGap = OfflineChecker::offlineSeconds(NodeType::Camera);
                $samples = Detection::query()
                    ->where('node_id', $alert->node_id)
                    ->where('captured_at', '>=', $task->done_at) // the first demo frame can land in the same second as Done
                    ->where('captured_at', '<=', $deadline)
                    ->orderBy('captured_at')->orderBy('id')
                    ->get(['id', 'count', 'captured_at'])
                    ->map(fn (Detection $d) => [$d->captured_at, $d->hasLitter() ? self::BAD : self::GOOD]);
            } else {
                $samples = Reading::query()
                    ->where('park_id', $task->park_id)
                    ->when($alert?->node_id, fn ($q, $nodeId) => $q->where('node_id', $nodeId))
                    ->where('created_at', '>', $task->done_at)
                    ->where('created_at', '<=', $deadline)
                    ->orderBy('created_at')->orderBy('id')
                    ->get()
                    ->map(fn (Reading $r) => [$r->created_at, $this->state($alert, $task->park, $r)]);
            }

            // Litter in demo mode: N clean frames in a row instead of the time window.
            $cleanFrames = $alert?->type?->isLitter() ? Namaa::timing('verify_clean_frames') : 0;
            $goodRun = 0;
            $streakStart = null;
            $previousAt = null;
            $sawBad = false;
            $verified = false;

            foreach ($samples as [$at, $state]) {
                if ($previousAt && $previousAt->diffInSeconds($at, true) > $maxGap) {
                    $streakStart = null; // a gap is not "continuous"
                    $goodRun = 0;
                }

                if ($cleanFrames > 0) {
                    $goodRun = $state === self::GOOD ? $goodRun + 1 : 0;
                    $sawBad = $sawBad || $state === self::BAD;
                    $previousAt = $at;
                    if ($goodRun >= $cleanFrames) {
                        $verified = true;
                        break;
                    }

                    continue;
                }

                if ($state === self::BAD) {
                    $streakStart = null;
                    $sawBad = true;
                } elseif ($state === self::GOOD || $streakStart !== null) {
                    $streakStart ??= $at;
                    if ($streakStart->diffInSeconds($at, true) >= $window) {
                        $verified = true;
                        break;
                    }
                }

                $previousAt = $at;
            }

            if (! $verified && now()->lt($deadline)) {
                return null; // keep watching
            }

            $reason = $verified ? null : ($sawBad ? 'condition_returned' : 'no_data');
            $startedAt = $alert?->created_at ?? $task->created_at;

            $task->update([
                'verified' => $verified,
                'verified_at' => now(),
                'verify_reason' => $reason,
                'time_to_resolve_min' => $verified ? (int) max(1, round($startedAt->diffInSeconds(now(), true) / 60)) : null,
            ]);

            if ($verified && $alert && $alert->status === AlertStatus::Open) {
                $this->alerts->resolve($alert, 'task_verified');
                $this->status->recalculate($task->park);
            }

            $this->logger->log('task.verified', $task, ['after' => [
                'verified' => $verified, 'reason' => $reason, 'time_to_resolve_min' => $task->time_to_resolve_min,
            ]], companyId: $task->company_id);

            $this->tasks->broadcastUpdated($task);

            return $verified;
        });
    }

    /** Reading states: starts (or continues) the window, only continues a running one, breaks it. */
    private const GOOD = 'good';

    private const HOLD = 'hold';

    private const BAD = 'bad';

    /** What one reading means for the alert's condition (GOOD / HOLD / BAD). */
    private function state(?Alert $alert, Park $park, Reading $r): string
    {
        $moisture = $r->moisture_pct;

        return match ($alert?->type) {
            AlertType::Dry, AlertType::HeatStress => match (true) {
                RuleContext::faulty($r) || $moisture < $park->moisture_min => self::BAD,
                $moisture >= RuleContext::dryRecovered($park) => self::GOOD,
                default => self::HOLD,
            },
            AlertType::Over => match (true) {
                RuleContext::faulty($r) || $moisture > $park->moisture_max => self::BAD,
                $moisture <= RuleContext::overRecovered($park) => self::GOOD,
                default => self::HOLD,
            },
            // A task made from a park: within the range (with the margins to start).
            null => match (true) {
                RuleContext::faulty($r) || $moisture < $park->moisture_min || $moisture > $park->moisture_max => self::BAD,
                $moisture >= RuleContext::dryRecovered($park) && $moisture <= RuleContext::overRecovered($park) => self::GOOD,
                default => self::HOLD,
            },
            default => $this->cleared($alert, $r) ? self::GOOD : self::BAD,
        };
    }

    /** One reading shows a non-moisture alert's condition cleared. */
    private function cleared(Alert $alert, Reading $r): bool
    {
        $node = $alert->node;

        return match ($alert->type) {
            AlertType::Leak => $r->flow !== null && ! RuleContext::flowing($r),
            AlertType::NoFlow => $r->pump_on !== true || RuleContext::flowing($r),
            AlertType::Blockage => $r->pump_on !== true || $this->flowOk($node, $r),
            AlertType::SensorFault => ! RuleContext::faulty($r),
            AlertType::LowBattery => $r->battery_pct !== null && $r->battery_pct > Namaa::rule('battery_ok_pct'),
            // Offline: any reading. Litter is checked on camera frames, ndvi_drop has no readings check.
            default => true,
        };
    }

    private function flowOk(?Node $node, Reading $r): bool
    {
        $expected = $node?->expected_flow_l_min;

        return $expected === null || ($r->flow !== null && $r->flow >= $expected * Namaa::rule('blockage_ok_ratio'));
    }
}
