<?php

namespace App\Services;

use App\Enums\DataSource;
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
    public const NAMES = ['reset', 'dry', 'heat', 'leak', 'blockage', 'battery', 'offline', 'escalate', 'recover'];

    /** Flags that `recover` ends. */
    private const RECOVERABLE = ['leak', 'offline', 'heat', 'blockage', 'battery_forced'];

    public function __construct(
        private readonly AlertService $alerts,
        private readonly PumpService $pump,
        private readonly TaskService $tasks,
        private readonly ParkStatusService $status,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Run a scenario. With a company id, only that company's simulated parks
     * are touched (the API passes the admin's company).
     *
     * @return array<string, mixed> a short summary
     */
    public function run(string $name, ?User $by = null, ?int $companyId = null): array
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new InvalidArgumentException("Unknown scenario [$name].");
        }

        $nodes = $this->simulatedNodes($companyId);
        $this->ensureExpectedFlow($nodes, $by);

        $summary = match ($name) {
            'reset' => $this->reset($nodes, $by),
            'dry' => $this->dry($nodes),
            'heat' => $this->flag($nodes, 'heat_node', 'heat'),
            'leak' => $this->flag($nodes, 'leak_node', 'leak'),
            'blockage' => $this->flag($nodes, 'blockage_node', 'blockage'),
            'battery' => $this->lowBattery($nodes),
            'offline' => $this->flag($nodes, 'offline_node', 'offline'),
            'escalate' => $this->escalate($nodes, $by),
            'recover' => $this->recover($nodes),
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

    // ---- scenarios ----

    /** @param Collection<int, Node> $nodes */
    private function reset(Collection $nodes, ?User $by): array
    {
        $parkIds = $nodes->pluck('park_id')->unique()->values();
        $resolved = 0;
        $cancelled = 0;

        DB::transaction(function () use ($nodes, $parkIds, $by, &$resolved, &$cancelled) {
            foreach (Alert::open()->whereIn('park_id', $parkIds)->with('park')->get() as $alert) {
                $this->alerts->resolve($alert, 'scenario_reset');
                $resolved++;
            }

            foreach (Task::active()->whereIn('park_id', $parkIds)->get() as $task) {
                $this->tasks->cancel($task, $by, 'scenario_reset');
                $cancelled++;
            }

            foreach ($nodes as $node) {
                $this->clearFlags($node);
                $park = $node->park;

                if ($node->name === config('namaa.scenario.dry_node')) {
                    // Al Adel starts dry, with a pump, pump off, automatic irrigation off.
                    if (! $node->has_pump) {
                        $node->update(['has_pump' => true]);
                    }
                    Cache::forever($this->key($node, 'moisture'), config('namaa.scenario.reset_dry_pct'));

                    if ($park->auto_irrigation) {
                        $park->update(['auto_irrigation' => false]);
                        $this->logger->log('park.updated', $park, ['before' => ['auto_irrigation' => true], 'after' => ['auto_irrigation' => false]], user: $by);
                    }
                } else {
                    Cache::forever($this->key($node, 'moisture'), config('namaa.scenario.reset_healthy_pct'));
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

    public function hasBattery(Node $node): bool
    {
        return $node->source === DataSource::Simulated
            && in_array($node->name, config('namaa.simulator.battery_nodes', []), true);
    }

    /** @return Collection<int, Node> simulated sensor nodes, optionally of one company */
    private function simulatedNodes(?int $companyId): Collection
    {
        return Node::query()
            ->where('source', DataSource::Simulated)
            ->where('type', NodeType::Sensor)
            ->whereHas('park', fn ($q) => $q->where('source', DataSource::Simulated)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId)))
            ->with('park')
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, Node> $nodes */
    private function target(Collection $nodes, string $configKey): Node
    {
        $name = config("namaa.scenario.$configKey");
        $node = $nodes->firstWhere('name', $name);

        if (! $node) {
            throw new InvalidArgumentException("Simulated node [$name] not found.");
        }

        return $node;
    }

    private function clearFlags(Node $node): void
    {
        foreach (['moisture', 'watered', ...self::RECOVERABLE] as $flag) {
            Cache::forget($this->key($node, $flag));
        }
    }

    private function key(Node $node, string $flag): string
    {
        return "namaa:sim:{$node->id}:$flag";
    }
}
