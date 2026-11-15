<?php

namespace App\Support;

use App\Models\Park;

/** The map block of GET /api/demo/status: basemap and the bounds of the shown parks plus a margin. */
class MapBounds
{
    /** Makkah, used when no park is shown yet. */
    private const FALLBACK = [39.6, 21.2, 40.1, 21.7];

    /** @return array{basemap: string, bounds: list<float>, lock: bool, min_zoom: float, max_zoom: float} */
    public static function forShownParks(): array
    {
        $basemap = config('namaa.map.basemap') === 'esri' ? 'esri' : 'maptiler';

        return [
            'basemap' => $basemap,
            'bounds' => self::bounds(Park::shown()->withoutTest()->get(['id', 'lat', 'lng', 'polygon'])),
            'lock' => (bool) config('namaa.map.lock', false),
            'min_zoom' => (float) config('namaa.map.min_zoom', 2),
            'max_zoom' => (float) config('namaa.map.max_zoom', 22),
        ];
    }

    /**
     * @param  iterable<Park>  $parks
     * @return list<float> [west, south, east, north]
     */
    public static function bounds(iterable $parks, ?float $marginKm = null): array
    {
        $points = [];
        foreach ($parks as $park) {
            foreach (self::ring($park->polygon) as $p) {
                $points[] = [(float) $p[0], (float) $p[1]];
            }
            if ($park->lng !== null && $park->lat !== null) {
                $points[] = [(float) $park->lng, (float) $park->lat];
            }
        }
        if ($points === []) {
            return self::FALLBACK;
        }

        $lngs = array_column($points, 0);
        $lats = array_column($points, 1);
        $margin = $marginKm ?? (float) config('namaa.map.bounds_margin_km', 8);
        $dLat = $margin / 111.32;
        $dLng = $margin / (111.32 * max(0.1, cos(deg2rad((min($lats) + max($lats)) / 2))));

        return [round(min($lngs) - $dLng, 5), round(min($lats) - $dLat, 5), round(max($lngs) + $dLng, 5), round(max($lats) + $dLat, 5)];
    }

    /** @return list<array{0: float, 1: float}> the outer ring of a Polygon / MultiPolygon, empty when none */
    private static function ring(mixed $polygon): array
    {
        $coords = is_array($polygon) ? ($polygon['coordinates'] ?? []) : [];
        if (($polygon['type'] ?? null) === 'MultiPolygon') {
            return array_merge(...array_map(fn ($poly) => $poly[0] ?? [], $coords ?: [[]]));
        }

        return $coords[0] ?? [];
    }
}
