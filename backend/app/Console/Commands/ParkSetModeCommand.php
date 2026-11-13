<?php

namespace App\Console\Commands;

use App\Enums\MonitoringMode;
use App\Services\ParkModeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('namaa:parks:set-mode {park : id, slug or name} {mode : simulated | real_sensors | satellite_only} {--demo-role= : dry | leak | offline (simulated only)} {--dry-run : print what would change}')]
#[Description('Change a park monitoring mode (cap: 4 simulated parks per company). --dry-run changes nothing.')]
class ParkSetModeCommand extends Command
{
    public function handle(ParkModeService $modes): int
    {
        try {
            $mode = MonitoringMode::tryFrom((string) $this->argument('mode'))
                ?? throw new InvalidArgumentException('Mode must be simulated, real_sensors or satellite_only.');
            $plan = $modes->apply(ParkModeService::find((string) $this->argument('park')), $mode, $this->option('demo-role') ?: null, (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($plan['dry_run'] ? 'DRY RUN, nothing changed. ' : '')."Park #{$plan['park_id']} {$plan['park']}: {$plan['from']} -> {$plan['to']}");
        $this->line('Nodes: '.($plan['nodes'] === [] ? 'none touched' : implode(', ', $plan['nodes'])));
        $this->line("Alerts resolved: {$plan['alerts']}; tasks cancelled: {$plan['tasks']}");
        $this->line('Demo role: '.($plan['demo_role'] ?? 'none').($plan['role_taken_from'] ? " (taken from {$plan['role_taken_from']})" : '').(isset($plan['demo_role_cleared']) ? " (cleared: {$plan['demo_role_cleared']})" : ''));

        return self::SUCCESS;
    }
}
