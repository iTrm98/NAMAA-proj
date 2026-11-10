<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\DataSource;
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
 * Camera nodes count too while CAMERA_URL is set (every successful capture
 * sets their last_seen_at); with CAMERA_URL empty they are skipped. A camera
 * never changes the park status (that is the sensor nodes' job).
 */
class OfflineChecker
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly ParkStatusService $status,
    ) {}

    /**
     * Seconds without a report before a node is offline. A camera reports once per
     * capture (5 min, 10 s demo), so it gets at least 2.5 capture intervals.
     */
    public static function offlineSeconds(NodeType $type): int
    {
        $sensor = Namaa::timing('offline_seconds');

        return $type === NodeType::Camera
            ? max($sensor, (int) ceil(Namaa::timing('camera_capture_seconds') * 2.5))
            : $sensor;
    }

    private static function threshold(NodeType $type): \Illuminate\Support\Carbon
    {
        return now()->subSeconds(self::offlineSeconds($type));
    }

    /** Returns the number of offline alerts raised. */
    public function run(): int
    {
        // The scheduler was stalled (laptop asleep, Docker paused): boards need a moment to
        // reconnect, so skip this cycle instead of marking them all offline.
        if (SystemHealth::schedulerJustResumed()) {
            return 0;
        }

        $raised = 0;

        // Uses the last_seen_at index (range + IS NULL); type is filtered after.
        $stale = Node::query()
            ->where('type', NodeType::Sensor)
            ->whereHas('park', fn ($p) => $p->where('is_test', false)) // the bench: unplugged is normal
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', self::threshold(NodeType::Sensor)))
            ->get(['id', 'park_id', 'type']);

        if (CameraPipeline::enabled()) {
            $stale = $stale->concat(Node::query()
                ->where('type', NodeType::Camera)
                ->where('source', DataSource::Real->value) // Sim Cam only gets demo frames
                ->where('capture_mode', 'url') // a browser tab is not an outage when closed
                ->whereHas('park', fn ($p) => $p->where('is_test', false))
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', self::threshold(NodeType::Camera)))
                ->get(['id', 'park_id', 'type']));
        }

        foreach ($stale->groupBy('park_id') as $parkId => $nodes) {
            DB::transaction(function () use ($parkId, $nodes, &$raised) {
                $park = Park::lockForUpdate()->find($parkId);

                foreach ($nodes as $node) {
                    $node = Node::find($node->id);

                    // A reading (or frame) may have arrived since the query above.
                    if ($node->last_seen_at && $node->last_seen_at->gte(self::threshold($node->type))) {
                        continue;
                    }

                    $alreadyOpen = Alert::open()->where('park_id', $park->id)->where('node_id', $node->id)
                        ->where('type', AlertType::Offline)->exists();

                    if (! $alreadyOpen) {
                        $this->alerts->raise($park, $node, AlertType::Offline, AlertType::Offline->severity(), [
                            'last_seen_at' => $node->last_seen_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                            'offline_seconds' => self::offlineSeconds($node->type),
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

                // Blind on a thirsty park: only a sensor node watches the moisture.
                $thirsty = $alert->node?->type !== NodeType::Camera && Alert::open()->where('park_id', $alert->park_id)
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
