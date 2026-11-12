<?php

namespace App\Console\Commands;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Services\AlertService;
use App\Services\ParkStatusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 (B1): open offline alerts of real nodes that never reported are not
 * outages, the board is just not installed. Resolves them (never deletes; each
 * resolve is logged `alert.resolved`, reason `awaiting_install`) and recalculates
 * the parks. Linked tasks stay. The printed summary is also written to the app log.
 */
#[Signature('namaa:cleanup:awaiting-install {--dry-run : Print what would be resolved, change nothing}')]
#[Description('Resolve open offline alerts of real nodes that never reported (awaiting installation).')]
class CleanupAwaitingInstall extends Command
{
    public function handle(AlertService $alerts, ParkStatusService $status): int
    {
        $dry = (bool) $this->option('dry-run');

        $open = Alert::open()->where('type', AlertType::Offline->value)
            ->whereHas('node', fn ($q) => $q->awaitingInstall())
            ->with(['park', 'node'])->orderBy('id')->get();

        $lines = $open->map(fn (Alert $a) => sprintf('alert #%d, park "%s", node #%d "%s"',
            $a->id, $a->park->name_en, $a->node_id, $a->node->name))->all();

        $head = sprintf('%s %d open offline alert(s) of real nodes that never reported.', $dry ? 'Dry run: would resolve' : 'Resolving', $open->count());
        $this->line($head);
        foreach ($lines as $line) {
            $this->line('  '.$line);
        }

        if (! $dry) {
            foreach ($open as $alert) {
                $alerts->resolve($alert, 'awaiting_install');
            }
            foreach ($open->pluck('park')->unique('id') as $park) {
                $status->recalculate($park->refresh());
            }
        }

        Log::info('namaa:cleanup:awaiting-install', ['dry_run' => $dry, 'alerts' => $open->pluck('id')->all()]);
        $this->info($dry ? 'Nothing changed (dry run).' : sprintf('Resolved %d alert(s).', $open->count()));

        return self::SUCCESS;
    }
}
