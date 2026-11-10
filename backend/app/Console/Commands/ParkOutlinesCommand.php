<?php

namespace App\Console\Commands;

use App\Models\Park;
use App\Services\ActivityLogger;
use App\Services\ParkOutlines;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Real park outlines from OpenStreetMap. Without --write it only prints the
 * candidates. With --write it saves the picked outline (one that contains the
 * park's marker) as `polygon` and `area_m2`, both in data/seed/parks.json and
 * in the parks table, and logs park.outline_updated. It never changes a park's
 * id, name or coordinates. Afterwards, refetch NDVI for the changed parks:
 * php artisan namaa:ndvi --days=90 --sync --park=<id>
 */
#[Signature('namaa:parks:outlines {--write : save the picked outlines} {--park= : park ids, comma-separated (default: all)} {--radius=300 : search radius in metres}')]
#[Description('Find park outlines (leisure=park/garden within 300 m) on OpenStreetMap; --write saves them.')]
class ParkOutlinesCommand extends Command
{
    /** Overpass asks for a pause between queries. */
    private const PAUSE_SECONDS = 3;

    public function handle(ParkOutlines $outlines, ActivityLogger $logger): int
    {
        $seedPath = (string) config('namaa.parks_seed_path', base_path('../data/seed/parks.json'));
        $seed = is_file($seedPath) ? (string) file_get_contents($seedPath) : null;
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('park')))));
        $parks = Park::query()->when($ids !== [], fn ($q) => $q->whereKey($ids))->orderBy('id')->get();
        $changed = [];
        $failed = 0;

        foreach ($parks as $i => $park) {
            if ($i > 0) {
                sleep(self::PAUSE_SECONDS);
            }
            $this->newLine();
            $this->line("<options=bold>#{$park->id} {$park->name_en}</> ({$park->lat}, {$park->lng}), now {$park->area_m2} m²");

            try {
                $candidates = $outlines->candidates((float) $park->lat, (float) $park->lng, (int) $this->option('radius'));
            } catch (RuntimeException $e) {
                $failed++;
                $this->error('  '.$e->getMessage());

                continue;
            }

            if ($candidates === []) {
                $this->line('  No leisure=park/garden within '.$this->option('radius').' m.');

                continue;
            }
            $pick = ParkOutlines::pick($candidates);
            foreach ($candidates as $c) {
                $this->line(sprintf('  %s %-16s %-8s %-30s %8d m²  %4d m away%s',
                    $c === $pick ? '<fg=green>→</>' : ' ', $c['osm'], $c['leisure'], str($c['name'] ?? '(no name)')->limit(30),
                    $c['area_m2'], $c['distance_m'], $c['contains'] ? '  contains the marker' : ''));
            }
            if (! $pick) {
                $this->line('  <fg=yellow>No candidate contains the marker: nothing picked.</>');

                continue;
            }
            if (! $this->option('write')) {
                continue;
            }
            if ($park->polygon == $pick['polygon']) {
                $this->line("  Already saved ({$pick['osm']}).");

                continue;
            }

            $before = ['area_m2' => $park->area_m2, 'points' => count($park->polygon['coordinates'][0] ?? [])];
            $park->forceFill([
                'polygon' => $pick['polygon'], 'area_m2' => $pick['area_m2'],
                'outline_source' => 'osm', 'outline_reference' => "OpenStreetMap {$pick['osm']} (ODbL)", 'outline_by' => null, 'outline_at' => now(),
                'area_source' => 'osm', 'area_reference' => "Computed from the OpenStreetMap outline ({$pick['osm']})", 'area_by' => null, 'area_at' => now(),
            ])->save();
            $logger->log('park.outline_updated', $park, [
                'before' => $before,
                'after' => ['area_m2' => $pick['area_m2'], 'points' => count($pick['polygon']['coordinates'][0]), 'osm' => $pick['osm']],
            ], companyId: $park->company_id);
            if ($seed !== null) {
                $seed = self::updateSeed($seed, $park->name_en, $pick);
            }
            $changed[] = $park->id;
            $this->info("  Saved {$pick['osm']}: {$pick['area_m2']} m².");
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('Dry run. Add --write to save the picked outlines (→).');
        } elseif ($changed !== []) {
            $this->newLine();
            if ($seed !== null) {
                $target = is_writable($seedPath) ? $seedPath : storage_path('app/parks.outlines.json');
                file_put_contents($target, $seed);
                if ($target !== $seedPath) {
                    // data/ is mounted read-only in the containers: copy the file over on the host.
                    $this->warn("data/seed/parks.json is read-only here; wrote $target. On the host, copy backend/storage/app/parks.outlines.json to data/seed/parks.json.");
                }
            }
            $this->info('Updated '.count($changed).' park(s). Now refetch NDVI:');
            foreach ($changed as $id) {
                $this->line("  php artisan namaa:ndvi --days=90 --sync --park=$id");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Edit the park's entry in the seed file as text, so the diff shows only
     * area_m2, polygon_approximate, polygon_source and a one-line polygon. The
     * key, names and coordinates are never touched.
     */
    public static function updateSeed(string $text, string $nameEn, array $pick): string
    {
        $name = preg_quote(json_encode($nameEn, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '/');
        if (! preg_match('/"name_en": '.$name.',/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        $start = $m[0][1];
        $end = strpos($text, '"nodes"', $start) ?: strlen($text);
        $block = substr($text, $start, $end - $start);

        $block = preg_replace('/"area_m2": \d+,/', '"area_m2": '.$pick['area_m2'].',', $block, 1);
        $block = preg_replace('/\n *"polygon_source": .*,(?=\n)/', '', $block);
        $block = preg_replace('/\n *"polygon": \{.*\},(?=\n)/', '', $block);
        $polygon = json_encode($pick['polygon'], JSON_UNESCAPED_SLASHES);
        $polygon = str_replace([',', ':'], [', ', ': '], $polygon);
        $block = preg_replace_callback('/( *)"polygon_approximate": (true|false),\n/', fn ($p) => $p[1].'"polygon_approximate": false,'."\n"
            .$p[1].'"polygon_source": '.json_encode('OpenStreetMap '.$pick['osm'].' (ODbL)', JSON_UNESCAPED_SLASHES).",\n"
            .$p[1].'"polygon": '.$polygon.",\n", $block, 1);

        return substr($text, 0, $start).$block.substr($text, $end);
    }
}
