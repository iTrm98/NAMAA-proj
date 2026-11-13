<?php

namespace App\Console\Commands;

use App\Models\Park;
use App\Services\ParkAudit;
use App\Services\ParkOutlines;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Compare each park's saved outline with the OpenStreetMap green areas around it
 * (leisure=park, leisure=garden, landuse=grass within 300 m): outline and OSM
 * areas, coverage, overlap and a verdict. One polite Overpass query per park
 * (User-Agent naming NAMAA, no e-mail, a pause between queries). Read-only: it
 * changes nothing; fix an outline in the editor (admin park page) or with
 * namaa:parks:outlines --write. The table is also saved to
 * storage/app/parks-audit.json.
 */
#[Signature('namaa:parks:audit {--park= : park ids, comma-separated (default: all)} {--radius=300 : search radius in metres}')]
#[Description('Audit park outlines against OpenStreetMap green areas (overlap and verdict). Changes nothing.')]
class ParkAuditCommand extends Command
{
    private const PAUSE_SECONDS = 3;

    public function handle(ParkOutlines $osm): int
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('park')))));
        $parks = Park::query()->when($ids !== [], fn ($q) => $q->whereKey($ids))->orderBy('id')->get();
        $radius = (int) $this->option('radius');
        $rows = [];
        $failed = 0;

        foreach ($parks as $i => $park) {
            if ($i > 0 && ! app()->runningUnitTests()) {
                sleep(self::PAUSE_SECONDS);
            }
            $outline = \App\Services\ParkOutline::outerRing($park->polygon); // MultiPolygon: the largest part
            $source = $park->outline_source instanceof \BackedEnum ? $park->outline_source->value : (string) $park->outline_source;

            try {
                $r = ParkAudit::compare($outline, $osm->greenAreas((float) $park->lat, (float) $park->lng, $radius));
            } catch (RuntimeException $e) {
                $failed++;
                $r = ['outline_m2' => $park->area_m2, 'best' => null, 'covered_pct' => null, 'overlap_pct' => null,
                    'verdict' => 'error', 'nearest_m' => null, 'areas' => 0, 'error' => $e->getMessage()];
            }
            $rows[] = ['id' => $park->id, 'park' => $park->name_en, 'outline_source' => $source, 'area_m2' => $park->area_m2] + $r;
        }

        $this->table(
            ['#', 'Park', 'Outline', 'Outline m²', 'Best OSM area', 'OSM m²', 'Covered', 'Overlap', 'Verdict'],
            array_map(fn ($r) => [
                $r['id'], $r['park'], $r['outline_source'], number_format($r['outline_m2']),
                $r['best'] ? trim($r['best']['osm'].' '.$r['best']['tag'].' '.($r['best']['name'] ?? '')) : ($r['verdict'] === 'error' ? 'Overpass failed' : ($r['areas'] ? "none touching ({$r['areas']} within {$radius} m, nearest {$r['nearest_m']} m)" : "none within {$radius} m")),
                $r['best'] ? number_format($r['best']['area_m2']) : '-',
                $r['covered_pct'] === null ? '-' : $r['covered_pct'].' %',
                $r['overlap_pct'] === null ? '-' : $r['overlap_pct'].' %',
                $r['verdict'],
            ], $rows),
        );
        $this->line('Covered: share of the outline inside any OSM green area. Overlap: intersection over union with the best OSM area.');
        $this->line('Verdict: match ≥ '.ParkAudit::MATCH_PCT.' % overlap; partial: covered ≥ '.ParkAudit::PARTIAL_COVERED_PCT.' % or overlap ≥ '.ParkAudit::PARTIAL_OVERLAP_PCT.' %. Nothing was changed.');
        foreach (array_filter($rows, fn ($r) => isset($r['error'])) as $r) {
            $this->error("#{$r['id']} {$r['park']}: {$r['error']}");
        }

        if (! app()->runningUnitTests()) {
            file_put_contents(storage_path('app/parks-audit.json'), json_encode(['at' => now()->toIso8601String(), 'radius_m' => $radius, 'parks' => $rows],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
