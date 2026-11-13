<?php

namespace App\Console\Commands;

use App\Services\ParkModeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('namaa:parks:demo-role {park : id, slug or name} {step : 1 | 2 | 3 | dry | leak | offline} {--dry-run : print what would change}')]
#[Description('Give a simulated park the demo role of a step (1 dry, 2 leak, offline). The previous holder loses it.')]
class ParkDemoRoleCommand extends Command
{
    private const STEPS = ['1' => 'dry', '2' => 'leak', 'dry' => 'dry', 'leak' => 'leak', 'offline' => 'offline'];

    public function handle(ParkModeService $modes): int
    {
        $step = strtolower((string) $this->argument('step'));

        if ($step === '3' || $step === 'litter') {
            $this->info('Step 3 (litter) has no demo role: it runs on the demo stand camera, with the Al Adel simulated camera as the fallback.');

            return self::SUCCESS;
        }

        try {
            $role = self::STEPS[$step] ?? throw new InvalidArgumentException('Step must be 1, 2, 3, dry, leak or offline.');
            $plan = $modes->assignRole(ParkModeService::find((string) $this->argument('park')), $role, (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($plan['dry_run'] ? 'DRY RUN, nothing changed. ' : '')."{$plan['park']} takes the role [{$plan['role']}]"
            .($plan['takes_from'] ? " from {$plan['takes_from']}" : '').($plan['previous_role'] ? " (its previous role: {$plan['previous_role']})" : '').'.');
        $this->table(['Step', 'Park'], collect($plan['table'])->map(fn ($park, $step) => [$step, $park ?? '(config fallback)'])->values()->all());

        return self::SUCCESS;
    }
}
