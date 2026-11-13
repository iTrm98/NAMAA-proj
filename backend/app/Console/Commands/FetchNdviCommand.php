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
 * NDVI for every real park with an outline (or one with --park=ID). The demo stand and archived parks are
 * never measured. Queued by default (the daily schedule); --sync runs here and prints the result per park,
 * e.g. the recompute: php artisan namaa:ndvi --days=60 --sync --park=all --replace
 */
#[Signature('namaa:ndvi {--days=35 : days back from today} {--park=all : one park id, or all} {--sync : run now instead of queueing} {--replace : delete the window\'s v2 values and composites once the new answer has arrived}')]
#[Description('Fetch Sentinel-2 NDVI per park (green zone, composite, confidence) from the vision service and run the ndvi_drop rule.')]
class FetchNdviCommand extends Command
{
    public function handle(NdviService $ndvi): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $one = $this->option('park');
        $parks = Park::query()->parks()
            ->when($one && $one !== 'all', fn ($q) => $q->whereKey((int) $one))
            ->orderBy('id')->get()
            ->filter(fn (Park $p) => ! empty($p->polygon));

        if ($parks->isEmpty()) {
            $this->warn('No park with an outline.');

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            foreach ($parks as $park) {
                FetchNdvi::dispatch($park->id, $days, null, (bool) $this->option('replace'));
            }
            $this->info("Queued NDVI for {$parks->count()} park(s), last $days days.");

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($parks as $park) {
            try {
                $r = $ndvi->fetch($park, $days, (bool) $this->option('replace'));
                if ($r['skipped']) {
                    $this->warn("#{$park->id} {$park->name_en}: skipped ({$r['skipped']})");

                    continue;
                }
                $this->line(sprintf('#%d %s: %d values (%d new), headline %s, green cover %s%%, %s %s, %d composite date(s), drop rule: %s',
                    $park->id, $park->name_en, $r['values'], $r['inserted'],
                    $r['headline'] ?? '-', $r['green_cover_pct'] ?? '-', $r['level'] ?? '-',
                    $r['confidence'] === null ? '' : "({$r['confidence']})", $r['composite_dates'], $r['rule']['outcome']));
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
        if (($d['status'] ?? '') === 'kept') {
            return sprintf('  %s  kept     NDVI %.2f  %d clear px (%d pure)  clear %s', $d['date'], (float) ($d['mean'] ?? 0), (int) ($d['pixels'] ?? 0), (int) ($d['pure_pixels'] ?? 0), isset($d['clear_fraction']) ? round($d['clear_fraction'] * 100).'%' : '-');
        }

        return sprintf('  %s  dropped  %s', $d['date'], match ($d['reason'] ?? 'no_clear_pixels') {
            'outside_outline' => 'outside the outline (no pixel inside)',
            'no_data' => 'no data (missing assets, all nodata or read failed)',
            default => 'no clear pixel after the cloud mask',
        });
    }
}
