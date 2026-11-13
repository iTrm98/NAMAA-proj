<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\MonitoringMode;
use App\Enums\NodeType;
use App\Enums\PumpReason;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Demo scenarios for the simulated parks only (never the real ones). Used by
 * `php artisan namaa:scenario {name}` and POST /api/admin/demo/scenario.
 * Forced states live in the cache (shared by the backend, scheduler and queue
 * containers through the database cache store), one key per node:
 *
 *   namaa:sim:{nodeId}:moisture   next tick starts from this moisture % (pulled once)
 *   namaa:sim:{nodeId}:leak       flow while the pump is off, until `recover`
 *   namaa:sim:{nodeId}:offline    the simulator skips the node, until `recover`
 *   namaa:sim:{nodeId}:heat       temperature forced to about 42 °C, until `recover`
 *   namaa:sim:{nodeId}:blockage   pump flow about a third of expected, until `recover`
 *   namaa:sim:{nodeId}:battery_forced  battery held at this %, until `recover`
 *   namaa:sim:{nodeId}:battery    the simulated battery % (full precision)
 *   namaa:sim:{nodeId}:watered    set by the simulator while the pump runs: a "drying"
 *                                 node stops drying until the next `dry` or `reset`
 *
 * Every scenario also makes sure the simulated pump nodes have an expected
 * flow (blockage rule); real nodes stay null until their pump is measured.
 */
class ScenarioService
{
    public const NAMES = ['reset', 'dry', 'heat', 'leak', 'blockage', 'battery', 'offline', 'escalate', 'litter', 'recover'];

    /** Flags that `recover` ends. */
    private const RECOVERABLE = ['leak', 'offline', 'heat', 'blockage', 'battery_forced'];

    public function __construct(
        private readonly AlertService $alerts,
        private readonly PumpService $pump,
        private readonly TaskService $tasks,
        private readonly ParkStatusService $status,
        private readonly ActivityLogger $logger,
        private readonly CameraFeed $feed,
    ) {}

    /**
     * Run a scenario. With a company id, only that company's simulated parks
     * are touched (the API passes the admin's company). `$full` only matters for
     * `reset`: without it the demo story is reset (Al Adel dry with automatic irrigation on, forced faults, open
     * alerts and tasks of the simulated parks) and the healthy parks' soil, water
     * cycle, automatic irrigation and pump are left alone; with it the healthy
     * parks are put back to the start as well.
     *
     * @return array<string, mixed> a short summary
     */
    public function run(string $name, ?User $by = null, ?int $companyId = null, bool $full = false): array
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new InvalidArgumentException("Unknown scenario [$name].");
        }

        if ($name === 'litter') {
            // The camera, not the simulated sensor nodes: sample photos as the next frames.
            $summary = $this->feed->start('litter', $companyId);
            $this->logger->log('demo.scenario', null, ['after' => ['scenario' => $name] + $summary], user: $by, companyId: $companyId);

            return ['scenario' => $name] + $summary;
        }

        $nodes = $this->simulatedNodes($companyId);
        $this->ensureExpectedFlow($nodes, $by);

        $summary = match ($name) {
            'reset' => $this->reset($nodes, $by, $full) + $this->resetCamera($companyId, $by) + ['full' => $full],
            'dry' => $this->dry($nodes),
            'heat' => $this->flag($nodes, 'heat_node', 'heat'),
            'leak' => $this->flag($nodes, 'leak_node', 'leak'),
            'blockage' => $this->flag($nodes, 'blockage_node', 'blockage'),
            'battery' => $this->lowBattery($nodes),
            'offline' => $this->flag($nodes, 'offline_node', 'offline'),
            'escalate' => $this->escalate($nodes, $by),
            'recover' => $this->recover($nodes) + $this->recoverCamera($companyId),
        };

        $this->logger->log('demo.scenario', null, ['after' => ['scenario' => $name] + $summary], user: $by,
            companyId: $companyId ?? $nodes->first()?->park?->company_id);

        return ['scenario' => $name] + $summary;
    }

    // ---- flags read by the simulator ----

    public function takeMoisture(Node $node): ?float
    {
        $value = Cache::pull($this->key($node, 'moisture'));

        return $value === null ? null : (float) $value;
    }

    public function isLeaking(Node $node): bool
    {
        return (bool) Cache::get($this->key($node, 'leak'), false);
    }

    public function isOffline(Node $node): bool
    {
        return (bool) Cache::get($this->key($node, 'offline'), false);
    }

    public function isHot(Node $node): bool
    {
        return (bool) Cache::get($this->key($node, 'heat'), false);
    }

    public function isBlocked(Node $node): bool
    {
        return (bool) Cache::get($this->key($node, 'blockage'), false);
    }

    public function forcedBattery(Node $node): ?float
    {
        $value = Cache::get($this->key($node, 'battery_forced'));

        return $value === null ? null : (float) $value;
    }

    public function battery(Node $node): ?float
    {
        $value = Cache::get($this->key($node, 'battery'));

        return $value === null ? null : (float) $value;
    }

    public function setBattery(Node $node, float $pct): void
    {
        Cache::forever($this->key($node, 'battery'), $pct);
    }

    /** The pump watered this node: a "drying" story pauses (soil stays wet after irrigation). */
    public function markWatered(Node $node): void
    {
        Cache::forever($this->key($node, 'watered'), true);
    }

    public function isWatered(Node $node): bool
    {
        return (bool) Cache::get($this->key($node, 'watered'), false);
    }

    /** Stage mode: fast drying and a fast pump for the scenario story (see Simulator). */
    public function isStaged(Node $node): bool
    {
        return (bool) Cache::get($this->key($node, 'stage'), false);
    }

    public function unstage(Node $node): void
    {
        Cache::forget($this->key($node, 'stage'));
    }

    /** The simulated soil moisture without sensor noise (null: continue from the last reading). */
    public function trueMoisture(Node $node): ?float
    {
        $value = Cache::get($this->key($node, 'soil'));

        return $value === null ? null : (float) $value;
    }

    public function setTrueMoisture(Node $node, float $pct): void
    {
        Cache::forever($this->key($node, 'soil'), $pct);
    }

    // ---- scenarios ----

    /** @param Collection<int, Node> $nodes */
    private function reset(Collection $nodes, ?User $by, bool $full): array
    {
        $parkIds = $nodes->pluck('park_id')->unique()->values();
        $resolved = 0;
        $cancelled = 0;

        $dryNodeId = $this->findTarget($nodes, 'dry_node')?->id;

        DB::transaction(function () use ($nodes, $parkIds, $by, $full, $dryNodeId, &$resolved, &$cancelled) {
            // Satellite truth (ndvi_drop) is not part of the simulated story: Reset leaves it open.
            foreach (Alert::open()->whereIn('park_id', $parkIds)->where('type', '!=', AlertType::NdviDrop->value)->with('park')->get() as $alert) {
                $this->alerts->resolve($alert, 'scenario_reset');
                $resolved++;
            }

            foreach (Task::active()->whereIn('park_id', $parkIds)->get() as $task) {
                $this->tasks->cancel($task, $by, 'scenario_reset');
                $cancelled++;
            }

            foreach ($nodes as $node) {
                $isDry = $node->id === $dryNodeId;
                $park = $node->park;
                $forcedBattery = $this->forcedBattery($node) !== null;

                if (! $full && ! $isDry) {
                    // Demo-story reset: a healthy park only loses its forced faults; its soil,
                    // water cycle, irrigation setting and pump stay as they are.
                    foreach (self::RECOVERABLE as $flag) {
                        Cache::forget($this->key($node, $flag));
                    }
                    $this->ensurePump($node);
                    if ($forcedBattery && $this->hasBattery($node)) {
                        $this->setBattery($node, (float) config('namaa.scenario.reset_battery_pct'));
                    }
                    $this->status->recalculate($park);

                    continue;
                }

                $this->clearFlags($node);

                if ($isDry) {
                    // Al Adel starts fine (35 %, above its minimum), with a pump, pump off, automatic irrigation ON: the story is
                    // "NAMAA waters it by itself" (the worker story starts with a fault scenario).
                    $this->ensurePump($node);
                    // Not on stage: it holds this value (real-time physics) until `dry` is pressed.
                    Cache::forever($this->key($node, 'moisture'), config('namaa.scenario.reset_dry_pct'));

                    if (! $park->auto_irrigation) {
                        $park->update(['auto_irrigation' => true]);
                        $this->logger->log('park.updated', $park, ['before' => ['auto_irrigation' => false], 'after' => ['auto_irrigation' => true]], user: $by);
                    }
                } else {
                    // Healthy parks keep their soil (moving it would add or remove water
                    // that was never pumped, and skew the water comparison); only a value
                    // outside the range is brought back to the reset value.
                    $soil = $this->trueMoisture($node) ?? (float) ($node->last_moisture_pct ?? config('namaa.scenario.reset_healthy_pct'));
                    if ($soil < $park->moisture_min || $soil > $park->moisture_max) {
                        Cache::forever($this->key($node, 'moisture'), config('namaa.scenario.reset_healthy_pct'));
                    }

                    // NAMAA waters the healthy parks by itself.
                    $this->ensurePump($node);
                    if ($node->has_pump && ! $park->auto_irrigation) {
                        $park->update(['auto_irrigation' => true]);
                        $this->logger->log('park.updated', $park, ['before' => ['auto_irrigation' => false], 'after' => ['auto_irrigation' => true]], user: $by);
                    }
                }

                if ($this->hasBattery($node)) {
                    $this->setBattery($node, (float) config('namaa.scenario.reset_battery_pct'));
                }

                if ($node->has_pump && $node->pump_target) {
                    $this->pump->set($node, false, PumpReason::System, $by);
                }

                $this->status->recalculate($park);
            }
        });

        return ['alerts_resolved' => $resolved, 'tasks_cancelled' => $cancelled, 'parks' => $parkIds->count()];
    }

    /** @param Collection<int, Node> $nodes */
    private function dry(Collection $nodes): array
    {
        $node = $this->target($nodes, 'dry_node');
        Cache::forget($this->key($node, 'watered'));
        Cache::forever($this->key($node, 'stage'), true);
        Cache::forever($this->key($node, 'moisture'), $node->park->moisture_min - 4);

        return ['node_id' => $node->id, 'park_id' => $node->park_id];
    }

    /** @param Collection<int, Node> $nodes */
    private function flag(Collection $nodes, string $configKey, string $flag): array
    {
        $node = $this->target($nodes, $configKey);
        Cache::forever($this->key($node, $flag), true);

        return ['node_id' => $node->id, 'park_id' => $node->park_id];
    }

    /** @param Collection<int, Node> $nodes */
    private function lowBattery(Collection $nodes): array
    {
        $node = $this->target($nodes, 'battery_node');
        Cache::forever($this->key($node, 'battery_forced'), (float) config('namaa.scenario.battery_pct'));

        return ['node_id' => $node->id, 'park_id' => $node->park_id];
    }

    /**
     * The newest open (not started) task of these parks gets due_at = now, so
     * the next escalation run raises it (or flags it overdue when urgent).
     *
     * @param  Collection<int, Node>  $nodes
     */
    private function escalate(Collection $nodes, ?User $by): array
    {
        $companyIds = $nodes->map(fn (Node $n) => $n->park->company_id)->unique()->values();

        $task = Task::where('status', TaskStatus::Open)->whereIn('company_id', $companyIds)
            ->latest()->orderByDesc('id')->first();

        if (! $task) {
            throw new InvalidArgumentException('There is no open, not started task to escalate.');
        }

        $before = $task->due_at?->utc()->format('Y-m-d\TH:i:s\Z');
        $task->update(['due_at' => now()]);
        $this->logger->log('task.updated', $task, [
            'before' => ['due_at' => $before],
            'after' => ['due_at' => $task->due_at->utc()->format('Y-m-d\TH:i:s\Z'), 'reason' => 'scenario_escalate'],
        ], user: $by, companyId: $task->company_id);
        $this->tasks->broadcastUpdated($task);

        return ['task_id' => $task->id, 'park_id' => $task->park_id];
    }

    /** @param Collection<int, Node> $nodes */
    private function recover(Collection $nodes): array
    {
        foreach ($nodes as $node) {
            foreach (self::RECOVERABLE as $flag) {
                Cache::forget($this->key($node, $flag));
            }
            if ($this->hasBattery($node)) {
                $this->setBattery($node, (float) config('namaa.scenario.reset_battery_pct'));
            }
        }

        return ['nodes' => $nodes->count()];
    }

    /** Reset: stop the sample feed; litter alerts raised from demo frames are resolved. @return array<string, mixed> */
    private function resetCamera(?int $companyId, ?User $by): array
    {
        $this->feed->stop();
        $resolved = 0;

        $alerts = Alert::open()
            ->whereIn('type', [AlertType::Litter->value, AlertType::LitterRepeated->value])
            ->whereHas('detection', fn ($q) => $q->whereIn('origin', ['upload', 'scenario']))
            ->when($companyId, fn ($q) => $q->whereHas('park', fn ($p) => $p->where('company_id', $companyId)))
            ->with('park')->get();

        foreach ($alerts as $alert) {
            $this->alerts->resolve($alert, 'scenario_reset');
            foreach ($alert->activeTasks()->get() as $task) {
                $this->tasks->cancel($task, $by, 'scenario_reset');
            }
            $resolved++;
        }

        return ['litter_alerts_resolved' => $resolved];
    }

    /** Recover: a running litter feed switches to the clean photos. @return array<string, mixed> */
    private function recoverCamera(?int $companyId): array
    {
        if (($this->feed->current()['kind'] ?? null) !== 'litter') {
            return [];
        }

        try {
            return ['camera' => $this->feed->start('clean', $companyId)];
        } catch (InvalidArgumentException $e) {
            $this->feed->stop();

            return ['camera' => ['feed' => null, 'message' => $e->getMessage()]];
        }
    }

    // ---- helpers ----

    /**
     * Simulated pump nodes get the expected flow of the simulated pump
     * (blockage rule). Logged once per node when it is set.
     *
     * @param  Collection<int, Node>  $nodes
     */
    private function ensureExpectedFlow(Collection $nodes, ?User $by): void
    {
        $expected = (float) config('namaa.simulator.expected_flow_l_min');

        foreach ($nodes as $node) {
            if ($node->has_pump && $node->expected_flow_l_min === null) {
                $node->update(['expected_flow_l_min' => $expected]);
                $this->logger->log('node.updated', $node, [
                    'before' => ['expected_flow_l_min' => null],
                    'after' => ['expected_flow_l_min' => $expected],
                ], user: $by, companyId: $node->park->company_id);
            }
        }
    }

    /** A simulated demo park has a pump (and its expected flow); a node that lacks one gets it. */
    private function ensurePump(Node $node): void
    {
        if ($node->has_pump) {
            return;
        }

        $node->update(['has_pump' => true, 'pump_target' => false, 'expected_flow_l_min' => (float) config('namaa.simulator.expected_flow_l_min')]);
        $this->logger->log('node.updated', $node, ['before' => ['has_pump' => false], 'after' => ['has_pump' => true, 'reason' => 'scenario_reset']],
            companyId: $node->park->company_id);
    }

    public function hasBattery(Node $node): bool
    {
        return $node->source === DataSource::Simulated && $node->type === NodeType::Sensor;
    }

    /** @return Collection<int, Node> simulated sensor nodes, optionally of one company */
    private function simulatedNodes(?int $companyId): Collection
    {
        return Node::query()
            ->where('source', DataSource::Simulated)
            ->where('type', NodeType::Sensor)
            ->whereNull('detached_at')
            ->whereHas('park', fn ($q) => $q->parks()->where('monitoring_mode', MonitoringMode::Simulated->value)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId)))
            ->with('park')
            ->orderBy('id')
            ->get();
    }

    /** Config key -> the demo role whose park it follows (heat, blockage and battery follow dry). */
    private const ROLE_OF = [
        'dry_node' => 'dry', 'heat_node' => 'dry', 'battery_node' => 'dry', 'blockage_node' => 'dry',
        'leak_node' => 'leak', 'offline_node' => 'offline',
    ];

    /**
     * The node a scenario acts on: the simulated sensor node of the park holding the
     * matching demo role; else the config node name (today's behaviour); else, for
     * offline, the last other simulated node. Null when none.
     *
     * @param  Collection<int, Node>  $nodes
     */
    private function findTarget(Collection $nodes, string $configKey): ?Node
    {
        $role = self::ROLE_OF[$configKey] ?? null;
        $node = $role ? $nodes->first(fn (Node $n) => $n->park->demo_role === $role) : null;
        $node ??= $nodes->firstWhere('name', config("namaa.scenario.$configKey"));

        // Alhilal (Sim 3) may belong to the second demo company (namaa:demo:second-company):
        // then the first company's offline scenario uses its last other simulated node.
        if (! $node && $configKey === 'offline_node') {
            $dry = $this->findTarget($nodes, 'dry_node');
            $node = $nodes->reject(fn (Node $n) => $n->id === $dry?->id)->last();
        }

        return $node;
    }

    /** @param Collection<int, Node> $nodes */
    private function target(Collection $nodes, string $configKey): Node
    {
        $node = $this->findTarget($nodes, $configKey);

        if (! $node) {
            $name = config("namaa.scenario.$configKey");
            throw new InvalidArgumentException("Simulated node [$name] not found.");
        }

        return $node;
    }

    /**
     * The demo story targets of a company (GET /admin/demo/story): the node per step,
     * null when the company has no simulated node for it.
     *
     * @return array{dry: ?Node, leak: ?Node, offline: ?Node}
     */
    public function storyNodes(?int $companyId): array
    {
        $nodes = $this->simulatedNodes($companyId);

        return [
            'dry' => $this->findTarget($nodes, 'dry_node'),
            'leak' => $this->findTarget($nodes, 'leak_node'),
            'offline' => $this->findTarget($nodes, 'offline_node'),
        ];
    }

    /** Forget every forced and simulated state of a node (a park leaving the simulation). */
    public function forgetNode(Node $node): void
    {
        $this->clearFlags($node);
        foreach (['battery', 'soil'] as $flag) {
            Cache::forget($this->key($node, $flag));
        }
    }

    private function clearFlags(Node $node): void
    {
        foreach (['moisture', 'watered', 'stage', ...self::RECOVERABLE] as $flag) {
            Cache::forget($this->key($node, $flag));
        }
    }

    private function key(Node $node, string $flag): string
    {
        return self::cacheKey($node->id, $flag);
    }

    public static function cacheKey(int $nodeId, string $flag): string
    {
        return "namaa:sim:{$nodeId}:$flag";
    }
}
