<?php

namespace App\Services;

use App\Enums\GeometrySource;
use App\Enums\VerificationStatus;
use App\Jobs\RecomputeNdvi;
use App\Models\Park;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;

/**
 * Park outlines drawn in the admin editor (Sprint 3.1, D2/D3): geometry checks,
 * the geodesic area, saving with its source, and the NDVI recompute.
 *
 * Polygons are GeoJSON (coordinates [lng, lat], one outer ring, closed).
 */
class ParkOutline
{
    /** Every point of an outline must be this close to the park's map marker. */
    public const MAX_DISTANCE_M = 2000;

    public const SOURCES = ['official', 'traced', 'osm', 'approximate'];

    public function __construct(private readonly ActivityLogger $logger) {}

    /** Area of a closed ring in m² (see Geo::ringArea). @param list<array{0: float, 1: float}> $ring */
    public static function area(array $ring): float
    {
        return Geo::ringArea($ring);
    }

    /** The ring with its first point repeated at the end. @param list<array{0: float, 1: float}> $ring */
    public static function closed(array $ring): array
    {
        return Geo::closeRing($ring);
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
                'geometry_source' => GeometrySource::Traced,
                'geometry_updated_at' => now(),
                'verification_status' => VerificationStatus::Unverified,
                'verified_by' => null,
                'verified_at' => null,
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

    /**
     * Save an outline in the v2 shape (Sprint 6.2): a boundary and/or a green zone drawn on
     * imagery. Drawing marks the geometry traced and unverified unless the editor ticked
     * "checked". Returns the warnings for the answer.
     *
     * @param  array<string, mixed>  $input  validated: boundary?, green_zone?, imagery, checked
     * @return list<string>
     */
    public function saveGeometry(Park $park, array $input, User $by): array
    {
        $before = [
            'area_m2' => $park->area_m2,
            'green_zone_area_m2' => $park->green_zone_area_m2,
            'geometry_source' => $park->geometry_source?->value,
            'verification_status' => $park->verification_status?->value,
        ];
        $reference = $input['imagery'] === 'esri'
            ? 'Traced on Esri World Imagery in the NAMAA editor'
            : 'Traced on MapTiler satellite imagery in the NAMAA editor';
        $checked = (bool) $input['checked'];
        $now = now();
        $attrs = [
            'geometry_source' => GeometrySource::Traced,
            'geometry_updated_at' => $now,
            'outline_source' => 'traced',
            'outline_reference' => $reference,
            'outline_by' => $by->id,
            'outline_at' => $now,
            'verification_status' => $checked ? VerificationStatus::Verified : VerificationStatus::Unverified,
            'verified_by' => $checked ? $by->id : null,
            'verified_at' => $checked ? $now : null,
            'ndvi_recompute_at' => $now,
        ];

        if (! empty($input['boundary'])) {
            $boundary = $this->roundGeometry($input['boundary']);
            $attrs['polygon'] = $boundary;
            $attrs['area_m2'] = (int) round(Geo::area($boundary));
            if ($c = Geo::centroid($boundary)) {
                $attrs['lng'] = round($c[0], 7);
                $attrs['lat'] = round($c[1], 7);
            }
            $attrs += [
                'area_source' => 'traced', 'area_reference' => 'Computed from the outline (geodesic area)',
                'area_by' => $by->id, 'area_at' => $now,
            ];
        }
        if (array_key_exists('green_zone', $input)) {
            $green = $input['green_zone'] ? $this->roundGeometry($input['green_zone']) : null;
            $attrs['green_zone'] = $green;
            $attrs['green_zone_area_m2'] = $green ? (int) round(Geo::area($green)) : null;
        }

        DB::transaction(function () use ($park, $attrs, $by, $before) {
            $park->forceFill($attrs)->save();
            $this->logger->log('park.outline_updated', $park, [
                'before' => $before,
                'after' => [
                    'area_m2' => $park->area_m2,
                    'green_zone_area_m2' => $park->green_zone_area_m2,
                    'geometry_source' => 'traced',
                    'verification_status' => $park->verification_status->value,
                    'reference' => $park->outline_reference,
                ],
            ], user: $by);
        });

        RecomputeNdvi::dispatch($park->id);

        return $this->warnings($park);
    }

    /** @return list<string> */
    public function warnings(Park $park): array
    {
        $green = $park->green_zone;
        $boundary = $park->polygon;
        if (! $green || ! $boundary) {
            return [];
        }
        $warnings = [];
        $polygons = $green['type'] === 'Polygon' ? [$green['coordinates']] : $green['coordinates'];
        foreach ($polygons as $polygon) {
            foreach ($polygon[0] ?? [] as $p) {
                if (! Geo::pointInPolygon((float) $p[0], (float) $p[1], $boundary)) {
                    $warnings[] = 'green_zone_outside_boundary';
                    break 2;
                }
            }
        }
        if (Geo::area($green) > Geo::area($boundary)) {
            $warnings[] = 'green_zone_larger_than_boundary';
        }

        return $warnings;
    }

    /** @param array<string, mixed> $geometry @return array<string, mixed> */
    private function roundGeometry(array $geometry): array
    {
        $round = function ($v) use (&$round) {
            return is_array($v) ? array_map($round, $v) : round((float) $v, 7);
        };

        return ['type' => $geometry['type'], 'coordinates' => $round($geometry['coordinates'])];
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
