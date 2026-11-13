<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\MonitoringMode;
use App\Enums\NodeType;
use App\Enums\PumpReason;
use App\Enums\SiteType;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Monitoring modes and demo roles (Sprint 6.2, Phase A). Used by the commands
 * namaa:parks:set-mode, namaa:parks:demo-role and namaa:parks:archive. Every
 * method takes `$dryRun`: it returns the plan (what would be touched) and changes nothing.
 *
 * The demo stays runnable: a park without a demo role changes nothing in the scenarios.
 */
class ParkModeService
{
    public const ROLES = ['dry', 'leak', 'offline'];

    private const TIME = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly AlertService $alerts,
        private readonly TaskService $tasks,
        private readonly PumpService $pump,
        private readonly ParkStatusService $status,
        private readonly ScenarioService $scenario,
        private readonly ActivityLogger $logger,
    ) {}

    /** Find a park by id, slug or name (exact, then Arabic / English substring). */
    public static function find(string $key): Park
    {
        $park = ctype_digit($key) ? Park::find((int) $key) : null;
        $park ??= Park::where('slug', $key)->first();

        if (! $park) {
            $matches = Park::where('name_en', $key)->orWhere('name_ar', $key)->get();
            if ($matches->isEmpty()) {
                $matches = Park::where('name_en', 'like', "%{$key}%")->orWhere('name_ar', 'like', "%{$key}%")->get();
            }
            if ($matches->count() > 1) {
                throw new InvalidArgumentException("Several parks match [{$key}]: ".$matches->map(fn (Park $p) => "#{$p->id} {$p->name_en}")->implode(', ').'. Use the id or the slug.');
            }
            $park = $matches->first();
        }

        return $park ?? throw new InvalidArgumentException("No park matches [{$key}].");
    }

    /**
     * Change a park's monitoring mode (and optionally give it a demo role).
     *
     * @return array<string, mixed> the plan (also what was done when not a dry run)
     */
    public function apply(Park $park, MonitoringMode $mode, ?string $demoRole = null, bool $dryRun = false): array
    {
        if ($demoRole !== null) {
            $this->assertRole($demoRole);
            if ($mode !== MonitoringMode::Simulated) {
                throw new InvalidArgumentException('A demo role needs the simulated mode.');
            }
        }
        if ($park->archived_at !== null) {
            throw new InvalidArgumentException('The park is archived.');
        }
        if ($park->site_type === SiteType::DemoStand) {
            throw new InvalidArgumentException('The demo stand cannot change its monitoring mode.');
        }

        $nodes = $park->nodes()->get();
        $plan = [
            'park' => $park->name_en, 'park_id' => $park->id,
            'from' => $park->monitoring_mode?->value, 'to' => $mode->value,
            'nodes' => [], 'alerts' => 0, 'tasks' => 0,
            'demo_role' => $mode === MonitoringMode::Simulated ? ($demoRole ?? $park->demo_role) : null,
            'role_taken_from' => null, 'dry_run' => $dryRun,
        ];

        switch ($mode) {
            case MonitoringMode::Simulated:
                $others = Park::where('company_id', $park->company_id)->whereNull('archived_at')
                    ->where('monitoring_mode', MonitoringMode::Simulated->value)->whereKeyNot($park->id)->count();
                if ($park->monitoring_mode !== MonitoringMode::Simulated && $others >= (int) config('namaa.max_simulated_parks')) {
                    throw new InvalidArgumentException('This company already has '.config('namaa.max_simulated_parks').' simulated parks (the cap). Set one to satellite_only first.');
                }
                $sim = $nodes->first(fn (Node $n) => $n->type === NodeType::Sensor && $n->source === DataSource::Simulated);
                $plan['nodes'] = $nodes->filter(fn (Node $n) => $n->type === NodeType::Sensor && $n->source === DataSource::Real && $n->detached_at === null)
                    ->map(fn (Node $n) => "detach {$n->name}")->values()->all();
                if ($plan['nodes'] !== []) {
                    $plan['alerts'] = $this->sensorAlerts($park)->count();
                    $plan['tasks'] = $this->tasksToCancel($park)->count();
                }
                $plan['nodes'][] = $sim ? ($sim->detached_at ? "re-attach {$sim->name}" : "keep {$sim->name}") : 'create '.$this->nextSimName($park);
                break;

            case MonitoringMode::RealSensors:
                $real = $nodes->filter(fn (Node $n) => $n->source === DataSource::Real);
                if ($real->isEmpty()) {
                    throw new InvalidArgumentException('real_sensors needs a park that has real nodes.');
                }
                $plan['nodes'] = $nodes->map(fn (Node $n) => $n->source === DataSource::Real
                    ? ($n->detached_at ? "re-attach {$n->name}" : "keep {$n->name}") : "detach {$n->name}")->values()->all();
                if ($nodes->contains(fn (Node $n) => $n->source !== DataSource::Real && $n->detached_at === null)) {
                    $plan['alerts'] = $this->sensorAlerts($park)->count();
                    $plan['tasks'] = $this->tasksToCancel($park)->count();
                }
                break;

            case MonitoringMode::SatelliteOnly:
                $plan['nodes'] = $nodes->filter(fn (Node $n) => $n->detached_at === null)->map(fn (Node $n) => "detach {$n->name}")->values()->all();
                $plan['alerts'] = $this->sensorAlerts($park)->count();
                $plan['tasks'] = $this->tasksToCancel($park)->count();
                if ($park->demo_role) {
                    $plan['demo_role_cleared'] = $park->demo_role;
                }
                break;
        }

        if ($plan['demo_role']) {
            $plan['role_taken_from'] = $this->roleHolder($park, $plan['demo_role'])?->name_en;
        }

        if ($dryRun) {
            return $plan;
        }

        DB::transaction(function () use ($park, $mode, $demoRole, $nodes, $plan) {
            $before = ['monitoring_mode' => $park->monitoring_mode?->value, 'source' => $park->source->value, 'demo_role' => $park->demo_role];

            match ($mode) {
                MonitoringMode::Simulated => $this->toSimulated($park, $nodes),
                MonitoringMode::RealSensors => $this->toReal($park, $nodes),
                MonitoringMode::SatelliteOnly => $this->toSatellite($park, $nodes),
            };

            $park->monitoring_mode = $mode;
            $park->source = $mode === MonitoringMode::Simulated ? DataSource::Simulated : DataSource::Real;
            if ($mode !== MonitoringMode::Simulated) {
                $park->demo_role = null;
            }
            $park->save();

            if ($mode === MonitoringMode::Simulated && $demoRole !== null) {
                $this->giveRole($park, $demoRole);
            }

            $this->status->recalculate($park->refresh());

            $this->logger->log('park.mode_changed', $park, [
                'before' => $before,
                'after' => [
                    'monitoring_mode' => $mode->value, 'source' => $park->source->value, 'demo_role' => $park->demo_role,
                    'reason' => 'mode_changed', 'alerts_resolved' => $plan['alerts'], 'tasks_cancelled' => $plan['tasks'],
                ],
            ], companyId: $park->company_id);
        });

        return $plan;
    }

    /**
     * Give a simulated park the demo role of a step (dry, leak or offline); the previous
     * holder loses it. @return array<string, mixed> the plan
     */
    public function assignRole(Park $park, string $role, bool $dryRun = false): array
    {
        $this->assertRole($role);
        if ($park->archived_at !== null || $park->monitoring_mode !== MonitoringMode::Simulated) {
            throw new InvalidArgumentException('A demo role needs a simulated, non-archived park.');
        }

        $holder = $this->roleHolder($park, $role);
        $plan = [
            'park' => $park->name_en, 'role' => $role, 'previous_role' => $park->demo_role,
            'takes_from' => $holder?->name_en, 'table' => $this->roleTable($park, $role), 'dry_run' => $dryRun,
        ];

        if (! $dryRun) {
            $previous = $park->demo_role;
            DB::transaction(function () use ($park, $role, $holder, $previous) {
                $this->giveRole($park, $role);
                $this->logger->log('park.demo_role_changed', $park, [
                    'before' => ['demo_role' => $previous, 'taken_from' => $holder?->id],
                    'after' => ['demo_role' => $role],
                ], companyId: $park->company_id);
            });
        }

        return $plan;
    }

    /**
     * Step -> park table of the company, optionally with $park taking $role.
     *
     * @return array<string, ?string>
     */
    public function roleTable(?Park $park = null, ?string $role = null): array
    {
        $holders = Park::when($park, fn ($q) => $q->where('company_id', $park->company_id))
            ->whereNull('archived_at')->whereNotNull('demo_role')->pluck('name_en', 'demo_role')->all();
        if ($park && $role) {
            $holders[$role] = $park->name_en;
        }

        return ['1 dry' => $holders['dry'] ?? null, '2 leak' => $holders['leak'] ?? null, 'offline' => $holders['offline'] ?? null];
    }

    /**
     * Archive a park: refused while it holds a demo role; otherwise satellite-only
     * detachment, then archived_at. @return array<string, mixed> the plan
     */
    public function archive(Park $park, bool $dryRun = false): array
    {
        if ($park->demo_role !== null) {
            throw new InvalidArgumentException("The park holds the demo role [{$park->demo_role}]. Move the role to another park first (namaa:parks:demo-role).");
        }
        if ($park->archived_at !== null) {
            throw new InvalidArgumentException('The park is already archived.');
        }

        $needsDetach = $park->monitoring_mode !== MonitoringMode::SatelliteOnly || $park->nodes()->whereNull('detached_at')->exists();
        $plan = $needsDetach
            ? $this->apply($park, MonitoringMode::SatelliteOnly, null, true)
            : ['park' => $park->name_en, 'park_id' => $park->id, 'nodes' => [], 'alerts' => 0, 'tasks' => 0];
        $plan['archive'] = true;
        $plan['dry_run'] = $dryRun;

        if ($dryRun) {
            return $plan;
        }

        DB::transaction(function () use ($park, $needsDetach) {
            if ($needsDetach) {
                $this->apply($park, MonitoringMode::SatelliteOnly);
            }
            $park->update(['archived_at' => now()]);
            $this->logger->log('park.archived', $park, [
                'before' => ['archived_at' => null],
                'after' => ['archived_at' => $park->archived_at?->utc()->format(self::TIME)],
            ], companyId: $park->company_id);
        });

        return $plan;
    }

    // ---- helpers ----

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Unknown demo role [{$role}]. Use one of: ".implode(', ', self::ROLES).'.');
        }
    }

    private function roleHolder(Park $park, string $role): ?Park
    {
        return Park::where('company_id', $park->company_id)->whereNull('archived_at')
            ->where('demo_role', $role)->whereKeyNot($park->id)->first();
    }

    /** The role moves: its previous holder in the company loses it, the park gets it. */
    private function giveRole(Park $park, string $role): void
    {
        $holder = $this->roleHolder($park, $role);
        if ($holder) {
            $holder->update(['demo_role' => null]);
            $this->logger->log('park.demo_role_changed', $holder, [
                'before' => ['demo_role' => $role], 'after' => ['demo_role' => null, 'moved_to' => $park->id],
            ], companyId: $holder->company_id);
        }
        $park->update(['demo_role' => $role]);
    }

    private function nextSimName(Park $park): string
    {
        $max = Node::whereHas('park', fn ($q) => $q->where('company_id', $park->company_id))
            ->where('name', 'like', 'Sim %')->pluck('name')
            ->map(fn (string $n) => (int) preg_replace('/\D+/', '', $n))->max() ?? 0;

        return 'Sim '.($max + 1);
    }

    /** @param Collection<int, Node> $nodes */
    private function toSimulated(Park $park, Collection $nodes): void
    {
        $toDetach = $nodes->filter(fn (Node $n) => $n->type === NodeType::Sensor && $n->source === DataSource::Real && $n->detached_at === null);
        if ($toDetach->isNotEmpty()) {
            $this->stopFeed($park);
        }
        foreach ($toDetach as $node) {
            $this->detach($node, $park);
        }

        $sim = $nodes->first(fn (Node $n) => $n->type === NodeType::Sensor && $n->source === DataSource::Simulated);
        if ($sim) {
            $sim->update(['detached_at' => null]);
        } else {
            $sim = Node::create([
                'park_id' => $park->id, 'type' => NodeType::Sensor, 'name' => $this->nextSimName($park),
                'has_pump' => true, 'pump_target' => false,
                'expected_flow_l_min' => (float) config('namaa.simulator.expected_flow_l_min'),
                'source' => DataSource::Simulated,
            ]);
            $this->logger->log('node.created', $sim, ['after' => ['name' => $sim->name, 'park_id' => $park->id, 'source' => 'simulated']], companyId: $park->company_id);
        }

        $park->auto_irrigation = true;
        $park->moisture_min ??= 25;
        $park->moisture_max ??= 70;
    }

    /** @param Collection<int, Node> $nodes */
    private function toReal(Park $park, Collection $nodes): void
    {
        if ($nodes->contains(fn (Node $n) => $n->source !== DataSource::Real && $n->detached_at === null)) {
            $this->stopFeed($park);
        }
        foreach ($nodes as $node) {
            if ($node->source === DataSource::Real) {
                $node->update(['detached_at' => null]);
            } elseif ($node->detached_at === null) {
                $this->detach($node, $park);
            }
        }
    }

    /** @param Collection<int, Node> $nodes */
    private function toSatellite(Park $park, Collection $nodes): void
    {
        $this->stopFeed($park);
        $park->auto_irrigation = false;
        foreach ($nodes->filter(fn (Node $n) => $n->detached_at === null) as $node) {
            $this->detach($node, $park);
        }
    }

    /** The old feed stops: its open sensor/litter alerts resolve and their tasks are cancelled (ndvi_drop stays). */
    private function stopFeed(Park $park): void
    {
        foreach ($this->sensorAlerts($park) as $alert) {
            $this->alerts->resolve($alert->setRelation('park', $park), 'mode_changed');
        }
        foreach ($this->tasksToCancel($park) as $task) {
            $this->tasks->cancel($task, null, 'mode_changed');
        }
    }

    /** Pump off, forced demo states forgotten, detached (readings stay). */
    private function detach(Node $node, Park $park): void
    {
        $node->setRelation('park', $park);
        if ($node->has_pump && $node->pump_target) {
            $this->pump->set($node, false, PumpReason::System);
        }
        if ($node->source === DataSource::Simulated) {
            $this->scenario->forgetNode($node);
        }
        $node->update(['detached_at' => now()]);
        $this->logger->log('node.updated', $node, [
            'before' => ['detached_at' => null],
            'after' => ['detached_at' => $node->detached_at?->utc()->format(self::TIME), 'reason' => 'mode_changed'],
        ], companyId: $park->company_id);
    }

    /** Open alerts that stop with the feed: everything but ndvi_drop. @return Collection<int, Alert> */
    private function sensorAlerts(Park $park): Collection
    {
        return Alert::open()->where('park_id', $park->id)->where('type', '!=', AlertType::NdviDrop->value)->get();
    }

    /** Active tasks, except those on a kept ndvi_drop alert. @return Collection<int, Task> */
    private function tasksToCancel(Park $park): Collection
    {
        $kept = Alert::where('park_id', $park->id)->where('type', AlertType::NdviDrop->value)->select('id');

        return Task::active()->where('park_id', $park->id)
            ->where(fn ($q) => $q->whereNull('alert_id')->orWhereNotIn('alert_id', $kept))
            ->get();
    }
}
