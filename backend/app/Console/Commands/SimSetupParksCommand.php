<?php

namespace App\Console\Commands;

use App\Enums\DataSource;
use App\Enums\MonitoringMode;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\Park;
use App\Services\ActivityLogger;
use App\Services\ParkModeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Sprint 6.3: the simulated demo parks of namaa.parks.sim_set (Al-Ju'ranah, King Abdullah) get a
 * simulated sensor node with pump and flow (the Simulator drives every simulated sensor node of a
 * simulated park), "Sim Cam" moves to namaa.parks.sim_camera_park, and the demo roles
 * (step 1 dry, step 2 leak) are given. Idempotent. The simulated-park cap may need
 * namaa:parks:archive-set to run first.
 */
#[Signature('namaa:sim:setup-parks {--dry-run : print what would change}')]
#[Description('Give the sim_set parks a simulated sensor node with pump and flow, move Sim Cam, set demo roles.')]
class SimSetupParksCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
        $this->setAliases(['namaa:sim-nodes']);
    }

    public function handle(ParkModeService $modes, ActivityLogger $logger): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $ok = true;

        foreach ((array) config('namaa.parks.sim_set') as $entry) {
            $park = ParkArchiveSetCommand::match((string) $entry['name'])[0] ?? null;
            if (! $park) {
                $rows[] = [$entry['name'], '-', 'NOT FOUND'];
                $ok = false;

                continue;
            }

            try {
                $actions = $this->ensureSimNode($park, $modes, $dry, $logger);
                if (($entry['role'] ?? null) && $park->refresh()->demo_role !== $entry['role'] && $park->monitoring_mode === MonitoringMode::Simulated) {
                    $modes->assignRole($park, $entry['role'], $dry);
                    $actions[] = "demo role [{$entry['role']}]";
                } elseif (($entry['role'] ?? null) && $park->demo_role !== $entry['role']) {
                    $actions[] = "demo role [{$entry['role']}] (after it is simulated)";
                }
                $rows[] = [$entry['name'], "#{$park->id} {$park->name_en}", $actions === [] ? 'nothing to do' : implode('; ', $actions)];
            } catch (InvalidArgumentException $e) {
                $rows[] = [$entry['name'], "#{$park->id} {$park->name_en}", 'BLOCKED: '.$e->getMessage()];
                $ok = false;
            }
        }

        $rows[] = ['Sim Cam', '-', $this->moveCamera($dry, $logger) ?? 'NOT DONE'];
        $this->table(['Configured', 'Park', 'Actions'], $rows);
        $this->line($dry ? 'DRY RUN, nothing changed.' : 'Done.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function ensureSimNode(Park $park, ParkModeService $modes, bool $dry, ActivityLogger $logger): array
    {
        $actions = [];

        if ($park->monitoring_mode !== MonitoringMode::Simulated) {
            $plan = $modes->apply($park, MonitoringMode::Simulated, null, $dry);

            return ["set simulated: ".implode(', ', $plan['nodes'])];
        }

        $node = Node::where('park_id', $park->id)->where('type', NodeType::Sensor)->where('source', DataSource::Simulated)->orderBy('id')->first();
        if (! $node) {
            return $this->createSimNode($park, $dry, $logger);
        }
        if ($node->detached_at !== null) {
            $actions[] = "re-attach {$node->name}";
            $dry || $node->update(['detached_at' => null]);
        }
        if (! $node->has_pump || $node->expected_flow_l_min === null) {
            $actions[] = "pump and flow on {$node->name}";
            $dry || $node->update([
                'has_pump' => true, 'pump_target' => (bool) $node->pump_target,
                'expected_flow_l_min' => (float) config('namaa.simulator.expected_flow_l_min'),
            ]);
        }
        if ($actions !== [] && ! $dry) {
            $logger->log('node.updated', $node, ['after' => ['has_pump' => true, 'reason' => 'sim_setup']], companyId: $park->company_id);
        }

        return $actions;
    }

    /** @return list<string> */
    private function createSimNode(Park $park, bool $dry, ActivityLogger $logger): array
    {
        $max = Node::whereHas('park', fn ($q) => $q->where('company_id', $park->company_id))->where('name', 'like', 'Sim %')->pluck('name')
            ->map(fn (string $n) => (int) preg_replace('/\D+/', '', $n))->max() ?? 0;
        $name = 'Sim '.($max + 1);

        if (! $dry) {
            $node = Node::create([
                'park_id' => $park->id, 'type' => NodeType::Sensor, 'name' => $name, 'has_pump' => true, 'pump_target' => false,
                'expected_flow_l_min' => (float) config('namaa.simulator.expected_flow_l_min'), 'source' => DataSource::Simulated,
            ]);
            $logger->log('node.created', $node, ['after' => ['name' => $name, 'park_id' => $park->id, 'source' => 'simulated']], companyId: $park->company_id);
        }

        return ["create $name with pump and flow"];
    }

    private function moveCamera(bool $dry, ActivityLogger $logger): ?string
    {
        $target = ParkArchiveSetCommand::match((string) config('namaa.parks.sim_camera_park'))[0] ?? null;
        $cam = Node::where('name', SimCamera::NAME)->where('type', NodeType::Camera)->first();

        if (! $target) {
            return 'target park not found';
        }
        if (! $cam) {
            return 'no Sim Cam yet: run php artisan namaa:sim:camera, then this command again';
        }
        if ($cam->park_id === $target->id) {
            return "already on {$target->name_en}";
        }

        $from = $cam->park_id;
        if (! $dry) {
            $cam->update(['park_id' => $target->id]);
            $logger->log('node.updated', $cam, ['before' => ['park_id' => $from], 'after' => ['park_id' => $target->id, 'reason' => 'sim_setup']], companyId: $target->company_id);
        }

        return "move to {$target->name_en}";
    }
}
