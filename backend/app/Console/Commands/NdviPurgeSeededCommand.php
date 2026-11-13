<?php

namespace App\Console\Commands;

use App\Enums\MonitoringMode;
use App\Models\NdviReading;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Removes the invented NDVI values the old demo seeder wrote for the simulated parks. They are the old (v1)
 * rows that were never touched by a satellite fetch (created_at = updated_at) and were bulk-inserted at an
 * exact minute (seconds = 0), which a fetch never does. Real satellite values (v2) are never touched.
 */
#[Signature('namaa:ndvi:purge-seeded {--dry-run : list the rows, delete nothing}')]
#[Description('Delete the seeded fake NDVI rows of simulated parks (v1, never refreshed by a fetch).')]
class NdviPurgeSeededCommand extends Command
{
    public function handle(): int
    {
        if (! NdviReading::hasV2Columns()) {
            $this->error('Run php artisan migrate first (ndvi_readings.method is missing).');

            return self::FAILURE;
        }

        // created_at = updated_at (never refreshed by a fetch) and created at an exact minute; the second is
        // checked here, not in SQL, so the command behaves the same on every database.
        $rows = NdviReading::query()->where('method', 'v1')
            ->whereColumn('created_at', 'updated_at')
            ->whereHas('park', fn ($q) => $q->where('monitoring_mode', MonitoringMode::Simulated->value))
            ->orderBy('park_id')->orderBy('date')->get(['id', 'park_id', 'date', 'created_at'])
            ->filter(fn (NdviReading $r) => $r->created_at->second === 0);

        if ($rows->isEmpty()) {
            $this->info('No seeded NDVI rows found.');

            return self::SUCCESS;
        }

        foreach ($rows->groupBy('park_id') as $parkId => $group) {
            $this->line(sprintf('park #%d: %d row(s), %s to %s', $parkId, $group->count(), $group->first()->date->toDateString(), $group->last()->date->toDateString()));
        }
        $total = $rows->count();

        if ($this->option('dry-run')) {
            $this->info("$total row(s) would be deleted (dry run, nothing changed).");

            return self::SUCCESS;
        }

        $deleted = NdviReading::whereIn('id', $rows->pluck('id'))->delete();
        $this->info("$deleted row(s) deleted.");

        return self::SUCCESS;
    }
}
