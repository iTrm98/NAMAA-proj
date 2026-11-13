<?php

namespace App\Console\Commands;

use App\Services\ParkModeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('namaa:parks:archive {park : id, slug or name} {--dry-run : print what would change}')]
#[Description('Archive a park (satellite-only detachment, then archived_at). Refuses a park that holds a demo role.')]
class ParkArchiveCommand extends Command
{
    public function handle(ParkModeService $modes): int
    {
        try {
            $plan = $modes->archive(ParkModeService::find((string) $this->argument('park')), (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($plan['dry_run'] ? 'DRY RUN, nothing changed. ' : '')."Archive #{$plan['park_id']} {$plan['park']}");
        $this->line('Nodes: '.($plan['nodes'] === [] ? 'none touched' : implode(', ', $plan['nodes'])));
        $this->line("Alerts resolved: {$plan['alerts']}; tasks cancelled: {$plan['tasks']}");

        return self::SUCCESS;
    }
}
