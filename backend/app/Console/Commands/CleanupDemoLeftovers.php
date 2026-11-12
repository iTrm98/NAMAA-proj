<?php

namespace App\Console\Commands;

use App\Services\DataCleanup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cleans what tests and trial runs left in the demo data. Simulated parks and
 * nodes only: a real park (source real) is never touched. Safe to run again.
 */
#[Signature('namaa:cleanup:demo-leftovers {--dry-run : Print what would be done, change nothing}')]
#[Description('Cancel test tasks, delete Sim Cam frames that are not from data/samples (with their litter alerts and tasks), resolve their open alerts.')]
class CleanupDemoLeftovers extends Command
{
    public function handle(DataCleanup $cleanup): int
    {
        $plan = $cleanup->demoLeftoversPlan();
        $dry = (bool) $this->option('dry-run');
        $this->line($dry ? 'Dry run (simulated parks only):' : 'Cleaning (simulated parks only):');

        $this->table(['Task to cancel', 'Park', 'Status', 'Title'], $plan['tasks']->map(fn ($t) => [
            $t->id, $t->park_id, $t->status->value, $t->title,
        ])->all());
        $this->table(['Alert to resolve', 'Type'], $plan['alerts_to_resolve']->map(fn ($a) => [$a->id, $a->type->value])->all());
        $this->table(['Frame to delete', 'Origin', 'Sample', 'Captured (UTC)', 'Photo'], $plan['detections']->map(fn ($d) => [
            $d->id, $d->origin, $d->sample ?? '(none)', $d->captured_at?->toDateTimeString(), $d->photo_path,
        ])->all());
        $this->line(sprintf('Litter alerts to delete: %d, their tasks: %d.', $plan['delete_alerts']->count(), $plan['delete_tasks']->count()));

        $summary = sprintf('%d tasks cancelled, %d alerts resolved, %d frames, %d litter alerts and %d tasks deleted',
            $plan['tasks']->count(), $plan['alerts_to_resolve']->count(), $plan['detections']->count(),
            $plan['delete_alerts']->count(), $plan['delete_tasks']->count());

        if ($dry) {
            $this->info("Dry run: {$summary} would follow. Nothing changed.");

            return self::SUCCESS;
        }

        $files = $cleanup->runDemoLeftovers($plan);
        $this->info("Done: {$summary} ({$files} photo files removed).");

        return self::SUCCESS;
    }
}
