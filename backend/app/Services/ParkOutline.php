<?php

namespace App\Services;

use App\Jobs\RecomputeNdvi;
use App\Models\Park;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Park outlines drawn in the admin editor (Sprint 3.1, D2/D3): geometry checks,
 * the geodesic area, saving with its source, and the NDVI recompute.
 *
 * Polygons are GeoJSON (coordinates [lng, lat], one outer ring, closed).
 */
class ParkOutline
{
    /** WGS84 equatorial radius, the same as @turf/area, so the editor and the server agree. */
    private const EARTH_RADIUS_M = 6378137.0;

    /** Every point of an outline must be this close to the park's map marker. */
    public const MAX_DISTANCE_M = 2000;

    public const SOURCES = ['official', 'traced', 'osm', 'approximate'];

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * Area of a closed ring in m² on the sphere (Chamberlain and Duquette, as in
     * @turf/area): sum of (lng2 - lng1) x (2 + sin lat1 + sin lat2) x R² / 2.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public static function area(array $ring): float
    {
        $ring = self::closed($ring);
        $sum = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            [$lng1, $lat1] = $ring[$i];
            [$lng2, $lat2] = $ring[$i + 1];
            $sum += deg2rad($lng2 - $lng1) * (2 + sin(deg2rad($lat1)) + sin(deg2rad($lat2)));
        }

        return abs($sum * self::EARTH_RADIUS_M * self::EARTH_RADIUS_M / 2);
    }

    /** The ring with its first point repeated at the end. @param list<array{0: float, 1: float}> $ring */
    public static function closed(array $ring): array
    {
        $ring = array_values(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $ring));
        if ($ring !== [] && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return $ring;
    }

    /** Distinct points of the ring (without the closing point). @param list<array{0: float, 1: float}> $ring */
    public static function points(array $ring): int
    {
        return count(array_unique(array_map(fn ($p) => $p[0].','.$p[1], array_slice(self::closed($ring), 0, -1))));
    }

    /** True when two edges that are not neighbours cross or touch. @param list<array{0: float, 1: float}> $ring */
    public static function selfIntersects(array $ring): bool
    {
        $ring = self::closed($ring);
        $n = count($ring) - 1;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                    continue; // neighbours share a point
                }
                if (self::segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Largest distance (m) from the point to a point of the ring. @param list<array{0: float, 1: float}> $ring */
    public static function maxDistance(array $ring, float $lat, float $lng): float
    {
        return max(array_map(fn ($p) => self::distance($lat, $lng, $p[1], $p[0]), self::closed($ring)));
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371008.8 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Save an outline drawn in the editor, with its source; the area is computed
     * from it. Logs park.outline_updated and queues the NDVI recompute.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public function save(Park $park, array $ring, string $source, ?string $reference, User $by): Park
    {
        $ring = array_map(fn ($p) => [round($p[0], 7), round($p[1], 7)], self::closed($ring));
        $area = (int) round(self::area($ring));
        $before = [
            'area_m2' => $park->area_m2,
            'points' => isset($park->polygon['coordinates'][0]) ? self::points($park->polygon['coordinates'][0]) : 0,
            'outline_source' => $park->outline_source,
        ];

        DB::transaction(function () use ($park, $ring, $area, $source, $reference, $by, $before) {
            $park->forceFill([
                'polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                'area_m2' => $area,
                'outline_source' => $source,
                'outline_reference' => $reference ?: ($source === 'traced' ? 'Traced on satellite imagery (MapTiler) in the NAMAA editor' : null),
                'outline_by' => $by->id,
                'outline_at' => now(),
                'area_source' => $source,
                'area_reference' => 'Computed from the outline (geodesic area)',
                'area_by' => $by->id,
                'area_at' => now(),
                'ndvi_recompute_at' => now(),
            ])->save();

            $this->logger->log('park.outline_updated', $park, [
                'before' => $before,
                'after' => ['area_m2' => $area, 'points' => self::points($ring), 'outline_source' => $source, 'reference' => $park->outline_reference],
            ], user: $by);
        });

        RecomputeNdvi::dispatch($park->id);

        return $park;
    }

    /** @param array{0: float, 1: float} $p1 */
    private static function segmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d = fn ($a, $b, $c) => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $on = fn ($a, $b, $c) => min($a[0], $b[0]) <= $c[0] && $c[0] <= max($a[0], $b[0])
            && min($a[1], $b[1]) <= $c[1] && $c[1] <= max($a[1], $b[1]);

        $d1 = $d($p3, $p4, $p1);
        $d2 = $d($p3, $p4, $p2);
        $d3 = $d($p1, $p2, $p3);
        $d4 = $d($p1, $p2, $p4);

        if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))) {
            return true;
        }

        return ($d1 == 0 && $on($p3, $p4, $p1)) || ($d2 == 0 && $on($p3, $p4, $p2))
            || ($d3 == 0 && $on($p1, $p2, $p3)) || ($d4 == 0 && $on($p1, $p2, $p4));
    }
}
