<?php

namespace App\Console\Commands;

use App\Jobs\FetchNdvi;
use App\Models\Park;
use App\Services\NdviService;
use App\Services\VisionUnavailable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * NDVI for every park with an outline (or one with --park). Queued by default
 * (the daily schedule); --sync runs here and prints the result per park, e.g.
 * the 90-day backfill: php artisan namaa:ndvi --days=90 --sync
 */
#[Signature('namaa:ndvi {--days=10 : days back from today} {--park= : one park id} {--sync : run now instead of queueing}')]
#[Description('Fetch Sentinel-2 NDVI per park from the vision service and run the ndvi_drop rule.')]
class FetchNdviCommand extends Command
{
    /** The vision service's MIN_VALID_PIXELS, for the printed reason. */
    private const MIN_PIXELS = 10;

    public function handle(NdviService $ndvi): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $parks = Park::query()->parks()
            ->when($this->option('park'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get()
            ->filter(fn (Park $p) => ! empty($p->polygon));

        if ($parks->isEmpty()) {
            $this->warn('No park with an outline.');

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            foreach ($parks as $park) {
                FetchNdvi::dispatch($park->id, $days);
            }
            $this->info("Queued NDVI for {$parks->count()} park(s), last $days days.");

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($parks as $park) {
            try {
                $r = $ndvi->fetch($park, $days);
                $this->line(sprintf('#%d %s: %d values (%d new), latest %s, baseline %s, %s',
                    $park->id, $park->name_en, $r['values'], $r['inserted'],
                    $r['rule']['latest'] ?? '-', $r['rule']['baseline'] ?? '-', $r['rule']['outcome']));
                foreach ($r['dates'] as $d) {
                    $this->line($this->dateLine($d));
                }
            } catch (VisionUnavailable $e) {
                $failed++;
                $this->error("#{$park->id} {$park->name_en}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $d */
    private function dateLine(array $d): string
    {
        $pixels = (int) ($d['pixels'] ?? 0);
        if (($d['status'] ?? '') === 'kept') {
            return sprintf('  %s  kept     NDVI %.2f  %d clear px', $d['date'], (float) ($d['mean'] ?? 0), $pixels);
        }
        $why = match ($d['reason'] ?? 'too_few_pixels') {
            'outside_outline' => 'outside the outline (no pixel inside)',
            'no_data' => 'no data (missing assets, all nodata or read failed)',
            default => sprintf('only %d clear px (min %d)', $pixels, self::MIN_PIXELS),
        };

        return sprintf('  %s  dropped  %s', $d['date'], $why);
    }
}
