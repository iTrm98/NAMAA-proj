<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\NodeType;
use App\Enums\TaskPriority;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Support\Namaa;
use Illuminate\Support\Facades\DB;

/**
 * The offline rule (decision table, rule 5), run by the scheduler. A sensor
 * node is offline when it never reported or its last reading is older than
 * the offline window (5 min, 30 s in demo mode). Each offline node gets one
 * open `offline` alert; its park becomes `offline` when no sensor node is
 * online. The node's next reading resolves the alert (RulesEngine).
 * Camera nodes are skipped until the camera job sets their last_seen_at.
 */
class OfflineChecker
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly ParkStatusService $status,
    ) {}

    /** Returns the number of offline alerts raised. */
    public function run(): int
    {
        $threshold = now()->subSeconds(Namaa::timing('offline_seconds'));
        $raised = 0;

        // Uses the last_seen_at index (range + IS NULL); type is filtered after.
        $stale = Node::query()
            ->where('type', NodeType::Sensor)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $threshold))
            ->get(['id', 'park_id']);

        foreach ($stale->groupBy('park_id') as $parkId => $nodes) {
            DB::transaction(function () use ($parkId, $nodes, $threshold, &$raised) {
                $park = Park::lockForUpdate()->find($parkId);

                foreach ($nodes as $node) {
                    $node = Node::find($node->id);

                    // A reading may have arrived since the query above.
                    if ($node->last_seen_at && $node->last_seen_at->gte($threshold)) {
                        continue;
                    }

                    $alreadyOpen = Alert::open()->where('park_id', $park->id)->where('node_id', $node->id)
                        ->where('type', AlertType::Offline)->exists();

                    if (! $alreadyOpen) {
                        $this->alerts->raise($park, $node, AlertType::Offline, AlertType::Offline->severity(), [
                            'last_seen_at' => $node->last_seen_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                            'offline_seconds' => Namaa::timing('offline_seconds'),
                        ]);
                        $raised++;
                    }
                }

                $this->status->recalculate($park);
            });
        }

        $this->followUp();

        return $raised;
    }

    /**
     * Map first, task later. For each open offline alert, with "offline for"
     * measured from the node's last reading (or the alert, if it never reported):
     *  - after offline_task_delay_seconds (30 min, 2 min demo) the alert becomes
     *    task-ready (the "Create task" button appears);
     *  - it rises to high when the park also has an open dry / heat_stress alert
     *    (we are blind on a park that needs water), or after offline_long_seconds
     *    (6 h, 5 min demo).
     */
    public function followUp(): void
    {
        $alerts = Alert::open()->where('type', AlertType::Offline)->with(['park', 'node'])->get();

        foreach ($alerts as $alert) {
            DB::transaction(function () use ($alert) {
                $alert = Alert::lockForUpdate()->find($alert->id)?->loadMissing(['park', 'node']);

                if (! $alert || $alert->status !== AlertStatus::Open) {
                    return;
                }

                $since = $alert->node?->last_seen_at ?? $alert->created_at;
                $offlineFor = (int) $since->diffInSeconds(now(), true);

                if (! $alert->isTaskReady() && $offlineFor >= Namaa::timing('offline_task_delay_seconds')) {
                    $this->alerts->markTaskReady($alert);
                }

                if (! TaskPriority::High->isHigherThan($alert->priority)) {
                    return; // already high or urgent
                }

                $thirsty = Alert::open()->where('park_id', $alert->park_id)
                    ->whereIn('type', [AlertType::Dry->value, AlertType::HeatStress->value])->exists();

                if ($thirsty) {
                    $this->alerts->raisePriority($alert, TaskPriority::High, 'blind_on_dry');
                } elseif ($offlineFor >= Namaa::timing('offline_long_seconds')) {
                    $this->alerts->raisePriority($alert, TaskPriority::High, 'long_offline');
                }
            });
        }
    }
}
