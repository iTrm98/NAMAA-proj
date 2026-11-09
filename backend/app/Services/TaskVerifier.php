<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskStatus;
use App\Models\Alert;
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
 *    reading, or a gap longer than the offline window, restarts the window;
 *  - not verified when the window is not reached within the timeout after
 *    Done (60 min, 3 min in demo mode): reason `condition_returned` when a
 *    reading after Done still showed the problem, else `no_data`. The alert
 *    then stays open.
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

            $readings = Reading::query()
                ->where('park_id', $task->park_id)
                ->when($alert?->node_id, fn ($q, $nodeId) => $q->where('node_id', $nodeId))
                ->where('created_at', '>', $task->done_at)
                ->where('created_at', '<=', $deadline)
                ->orderBy('created_at')->orderBy('id')
                ->get();

            $streakStart = null;
            $previous = null;
            $sawBad = false;
            $verified = false;

            foreach ($readings as $reading) {
                if ($previous && $previous->created_at->diffInSeconds($reading->created_at, true) > $maxGap) {
                    $streakStart = null; // a gap is not "continuous"
                }

                if ($this->cleared($alert, $task->park, $reading)) {
                    $streakStart ??= $reading->created_at;
                    if ($streakStart->diffInSeconds($reading->created_at, true) >= $window) {
                        $verified = true;
                        break;
                    }
                } else {
                    $streakStart = null;
                    $sawBad = true;
                }

                $previous = $reading;
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

    /** One reading shows the alert's condition cleared. */
    private function cleared(?Alert $alert, Park $park, Reading $r): bool
    {
        $node = $alert?->node;

        return match ($alert?->type) {
            AlertType::Dry, AlertType::HeatStress => ! RuleContext::faulty($r) && $r->moisture_pct >= $park->moisture_min,
            AlertType::Over => ! RuleContext::faulty($r) && $r->moisture_pct <= $park->moisture_max,
            AlertType::Leak => $r->flow !== null && ! RuleContext::flowing($r),
            AlertType::NoFlow => $r->pump_on !== true || RuleContext::flowing($r),
            AlertType::Blockage => $r->pump_on !== true || $this->flowOk($node, $r),
            AlertType::SensorFault => ! RuleContext::faulty($r),
            AlertType::LowBattery => $r->battery_pct !== null && $r->battery_pct > Namaa::rule('battery_ok_pct'),
            AlertType::Offline, AlertType::Litter, AlertType::NdviDrop => true,
            // A task made from a park: the soil is within the park's range.
            null => ! RuleContext::faulty($r) && $r->moisture_pct >= $park->moisture_min && $r->moisture_pct <= $park->moisture_max,
        };
    }

    private function flowOk(?Node $node, Reading $r): bool
    {
        $expected = $node?->expected_flow_l_min;

        return $expected === null || ($r->flow !== null && $r->flow >= $expected * Namaa::rule('blockage_ok_ratio'));
    }
}
