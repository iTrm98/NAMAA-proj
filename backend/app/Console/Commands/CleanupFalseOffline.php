<?php

namespace App\Console\Commands;

use App\Services\DataCleanup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes the false "offline" alerts raised on simulated nodes by the scheduler
 * gap bug (Sprint 3), and tasks made only from them. Real nodes are never
 * touched. The activity logs of what happened stay; each deletion adds one.
 */
#[Signature('namaa:cleanup:false-offline {--dry-run : Print what would be deleted, delete nothing} {--before= : Cut-off (UTC), default config namaa.cleanup.false_offline_before}')]
#[Description('Delete false offline alerts on simulated nodes raised by the scheduler gap bug (with their tasks).')]
class CleanupFalseOffline extends Command
{
    public function handle(DataCleanup $cleanup): int
    {
        $before = Carbon::parse((string) ($this->option('before') ?: config('namaa.cleanup.false_offline_before')), 'UTC');
        $plan = $cleanup->falseOfflinePlan($before);
        $dry = (bool) $this->option('dry-run');

        $this->line(sprintf('%s offline alerts on simulated nodes raised before %s UTC with a reading in the %d s before they started.',
            $dry ? 'Dry run:' : 'Deleting', $before->toDateTimeString(), config('namaa.timings.offline_seconds.normal')));

        $rows = $plan['alerts']->groupBy('park_id')->map(fn ($alerts) => [
            $alerts->first()->park?->name_en ?? '#'.$alerts->first()->park_id,
            $alerts->count(),
            $plan['tasks']->whereIn('alert_id', $alerts->pluck('id'))->count(),
            $alerts->min('created_at')?->toDateTimeString(),
            $alerts->max('created_at')?->toDateTimeString(),
        ])->values()->all();
        $this->table(['Park', 'Alerts', 'Tasks', 'First', 'Last'], $rows);
        $this->line(sprintf('Total: %d alerts, %d tasks.', $plan['alerts']->count(), $plan['tasks']->count()));

        if ($dry) {
            $this->info('Nothing deleted (dry run).');

            return self::SUCCESS;
        }

        $cleanup->runFalseOffline($plan, $before);
        $this->info(sprintf('Deleted %d alerts and %d tasks; %d activity log entries written.',
            $plan['alerts']->count(), $plan['tasks']->count(), $plan['alerts']->count() + $plan['tasks']->count()));

        return self::SUCCESS;
    }
}
