<?php

namespace App\Services;

/**
 * Outline audit (Sprint 3.1): how well a park's saved outline matches the green
 * areas OpenStreetMap has around it. Read-only: it never changes a park.
 *
 * The overlap is measured by sampling a regular grid (about SAMPLES points) over
 * the outline and the OSM areas that touch it, in a local metre plane. That works
 * for concave outlines without a clipping library; at park sizes the error is well
 * under 1 %.
 *
 * - covered_pct: share of the outline that lies inside any OSM green area;
 * - overlap_pct: intersection over union with the best-matching OSM area;
 * - verdict: match (overlap ≥ 70 %), partial (covered ≥ 50 % or overlap ≥ 30 %),
 *   mismatch (OSM has green areas nearby, but not where the outline is),
 *   no_osm_data (nothing within the radius).
 */
class ParkAudit
{
    public const SAMPLES = 20_000;

    public const MATCH_PCT = 70;

    public const PARTIAL_COVERED_PCT = 50;

    public const PARTIAL_OVERLAP_PCT = 30;

    /**
     * @param  list<array{0: float, 1: float}>  $outline  closed [lng, lat] ring
     * @param  list<array<string, mixed>>  $areas  ParkOutlines::greenAreas() results
     * @return array{outline_m2: int, best: array<string, mixed>|null, covered_pct: float|null, overlap_pct: float|null, verdict: string, nearest_m: int|null, areas: int}
     */
    public static function compare(array $outline, array $areas): array
    {
        $result = ['outline_m2' => (int) round(ParkOutline::area($outline)), 'best' => null, 'covered_pct' => null,
            'overlap_pct' => null, 'verdict' => 'no_osm_data', 'nearest_m' => null, 'areas' => count($areas)];
        if ($areas === [] || count($outline) < 4) {
            return $result;
        }
        $result['nearest_m'] = min(array_column($areas, 'distance_m'));

        // Local plane in metres around the outline.
        $lat0 = array_sum(array_column($outline, 1)) / count($outline);
        $lng0 = array_sum(array_column($outline, 0)) / count($outline);
        $kx = 111_320 * cos(deg2rad($lat0));
        $ky = 110_574;
        $toPlane = fn (array $ring) => array_map(fn ($p) => [($p[0] - $lng0) * $kx, ($p[1] - $lat0) * $ky], $ring);

        $shape = $toPlane($outline);
        $box = self::bbox($shape);
        $relevant = [];
        foreach ($areas as $area) {
            $ring = $toPlane($area['polygon']['coordinates'][0]);
            $b = self::bbox($ring);
            if ($b[0] <= $box[2] && $b[2] >= $box[0] && $b[1] <= $box[3] && $b[3] >= $box[1]) {
                $relevant[] = ['area' => $area, 'ring' => $ring, 'bbox' => $b];
            }
        }
        if ($relevant === []) {
            $result['covered_pct'] = 0.0;
            $result['overlap_pct'] = 0.0;
            $result['verdict'] = 'mismatch';

            return $result;
        }

        $grid = $box;
        foreach ($relevant as $r) {
            $grid = [min($grid[0], $r['bbox'][0]), min($grid[1], $r['bbox'][1]), max($grid[2], $r['bbox'][2]), max($grid[3], $r['bbox'][3])];
        }
        $step = max(0.25, sqrt(($grid[2] - $grid[0]) * ($grid[3] - $grid[1]) / self::SAMPLES));

        $inOutline = 0;
        $covered = 0;
        $inArea = array_fill(0, count($relevant), 0);
        $both = array_fill(0, count($relevant), 0);
        for ($y = $grid[1] + $step / 2; $y < $grid[3]; $y += $step) {
            for ($x = $grid[0] + $step / 2; $x < $grid[2]; $x += $step) {
                $o = $x >= $box[0] && $x <= $box[2] && $y >= $box[1] && $y <= $box[3] && self::contains($shape, $x, $y);
                $any = false;
                foreach ($relevant as $i => $r) {
                    $b = $r['bbox'];
                    if ($x < $b[0] || $x > $b[2] || $y < $b[1] || $y > $b[3] || ! self::contains($r['ring'], $x, $y)) {
                        continue;
                    }
                    $inArea[$i]++;
                    $any = true;
                    if ($o) {
                        $both[$i]++;
                    }
                }
                if ($o) {
                    $inOutline++;
                    $covered += $any ? 1 : 0;
                }
            }
        }

        $bestIou = -1.0;
        $best = null;
        foreach ($relevant as $i => $r) {
            $union = $inOutline + $inArea[$i] - $both[$i];
            $iou = $union > 0 ? $both[$i] / $union : 0.0;
            if ($iou > $bestIou) {
                [$bestIou, $best] = [$iou, $r['area']];
            }
        }

        $result['best'] = $best ? array_intersect_key($best, array_flip(['osm', 'name', 'tag', 'area_m2', 'distance_m'])) : null;
        $result['covered_pct'] = $inOutline > 0 ? round(100 * $covered / $inOutline, 1) : 0.0;
        $result['overlap_pct'] = round(100 * max(0, $bestIou), 1);
        $result['verdict'] = match (true) {
            $result['overlap_pct'] >= self::MATCH_PCT => 'match',
            $result['covered_pct'] >= self::PARTIAL_COVERED_PCT || $result['overlap_pct'] >= self::PARTIAL_OVERLAP_PCT => 'partial',
            default => 'mismatch',
        };

        return $result;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} minX, minY, maxX, maxY */
    private static function bbox(array $ring): array
    {
        $xs = array_column($ring, 0);
        $ys = array_column($ring, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    private static function contains(array $ring, float $x, float $y): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
