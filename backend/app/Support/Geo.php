<?php

namespace App\Support;

/**
 * GeoJSON geometry helpers (coordinates [lng, lat]). Areas use the same spherical
 * formula as @turf/area (WGS84 equatorial radius), so the editor and the server agree.
 */
class Geo
{
    public const EARTH_RADIUS_M = 6378137.0;

    /**
     * Area of one closed ring in m² (Chamberlain and Duquette): sum of
     * (lng2 - lng1) x (2 + sin lat1 + sin lat2) x R² / 2.
     *
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public static function ringArea(array $ring): float
    {
        $ring = self::closeRing($ring);
        $sum = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            [$lng1, $lat1] = $ring[$i];
            [$lng2, $lat2] = $ring[$i + 1];
            $sum += deg2rad($lng2 - $lng1) * (2 + sin(deg2rad($lat1)) + sin(deg2rad($lat2)));
        }

        return abs($sum * self::EARTH_RADIUS_M * self::EARTH_RADIUS_M / 2);
    }

    /** Area in m² of a Polygon (outer ring minus holes) or MultiPolygon; 0 for anything else. @param array<string, mixed>|null $geometry */
    public static function area(?array $geometry): float
    {
        return array_sum(array_map(self::polygonArea(...), self::polygons($geometry)));
    }

    /** @param array<string, mixed>|null $geometry @return array{0: float, 1: float}|null [lng, lat] area-weighted centroid of the outer rings */
    public static function centroid(?array $geometry): ?array
    {
        $sumArea = $cx = $cy = 0.0;
        foreach (self::polygons($geometry) as $polygon) {
            $ring = self::closeRing($polygon[0] ?? []);
            $a = 0.0;
            $x = 0.0;
            $y = 0.0;
            for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
                $cross = $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
                $a += $cross;
                $x += ($ring[$i][0] + $ring[$i + 1][0]) * $cross;
                $y += ($ring[$i][1] + $ring[$i + 1][1]) * $cross;
            }
            if (abs($a) < 1e-18) {
                continue;
            }
            $weight = abs($a / 2);
            $cx += ($x / (3 * $a)) * $weight;
            $cy += ($y / (3 * $a)) * $weight;
            $sumArea += $weight;
        }

        return $sumArea > 0 ? [$cx / $sumArea, $cy / $sumArea] : null;
    }

    /** @param array<string, mixed>|null $geometry @return array{0: float, 1: float, 2: float, 3: float}|null [minLng, minLat, maxLng, maxLat] */
    public static function bbox(?array $geometry): ?array
    {
        $points = [];
        foreach (self::polygons($geometry) as $polygon) {
            foreach ($polygon as $ring) {
                array_push($points, ...$ring);
            }
        }
        if ($points === []) {
            return null;
        }
        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);

        return [(float) min($lngs), (float) min($lats), (float) max($lngs), (float) max($lats)];
    }

    /** True when [lng, lat] lies inside the Polygon / MultiPolygon (inside a hole = outside). @param array<string, mixed>|null $geometry */
    public static function pointInPolygon(float $lng, float $lat, ?array $geometry): bool
    {
        foreach (self::polygons($geometry) as $polygon) {
            if (! self::inRing($lng, $lat, $polygon[0] ?? [])) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($polygon, 1) as $hole) {
                if (self::inRing($lng, $lat, $hole)) {
                    $inHole = true;
                    break;
                }
            }
            if (! $inHole) {
                return true;
            }
        }

        return false;
    }

    /** The ring with its first point repeated at the end. @param list<array{0: float, 1: float}> $ring */
    public static function closeRing(array $ring): array
    {
        $ring = array_values(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $ring));
        if ($ring !== [] && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return $ring;
    }

    /** @param array<string, mixed>|null $geometry @return list<list<list<array{0: float, 1: float}>>> polygons as lists of rings */
    private static function polygons(?array $geometry): array
    {
        return match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates'] ?? []],
            'MultiPolygon' => $geometry['coordinates'] ?? [],
            default => [],
        };
    }

    /** @param list<list<array{0: float, 1: float}>> $polygon */
    private static function polygonArea(array $polygon): float
    {
        if ($polygon === []) {
            return 0.0;
        }
        $holes = array_sum(array_map(self::ringArea(...), array_slice($polygon, 1)));

        return max(0.0, self::ringArea($polygon[0]) - $holes);
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private static function inRing(float $lng, float $lat, array $ring): bool
    {
        $ring = self::closeRing($ring);
        $inside = false;
        for ($i = 0, $j = count($ring) - 2; $i < count($ring) - 1; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && $lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
