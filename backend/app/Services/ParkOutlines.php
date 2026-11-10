<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Park outline candidates from OpenStreetMap (Overpass API): leisure=park or
 * leisure=garden ways and relations within 300 m of a park's marker. One
 * query per park, with a User-Agent naming NAMAA and no contact e-mail.
 *
 * A candidate: {osm, name, polygon (GeoJSON, [lng, lat]), area_m2,
 * distance_m (marker to the outline's centre), contains (marker inside)}.
 */
class ParkOutlines
{
    public const USER_AGENT = 'NAMAA-park-monitoring/1.0 (Tanmiathon 2026 demo; park outlines for satellite NDVI)';

    private const EARTH_M = 6_371_008.8;

    /** @return list<array<string, mixed>> best first: containing the marker, then nearest */
    public function candidates(float $lat, float $lng, int $radius = 300): array
    {
        return $this->search(sprintf(
            '[out:json][timeout:30];(way["leisure"~"^(park|garden)$"](around:%1$d,%2$F,%3$F);relation["leisure"~"^(park|garden)$"](around:%1$d,%2$F,%3$F););out geom;',
            $radius, $lat, $lng,
        ), $lat, $lng);
    }

    /**
     * Green areas for the outline audit (Sprint 3.1): leisure=park, leisure=garden and
     * landuse=grass ways and relations within the radius, in one query. Same shape as
     * candidates(), with `tag` ("leisure=park", "landuse=grass", ...).
     *
     * @return list<array<string, mixed>>
     */
    public function greenAreas(float $lat, float $lng, int $radius = 300): array
    {
        return $this->search(sprintf(
            '[out:json][timeout:30];(way["leisure"~"^(park|garden)$"](around:%1$d,%2$F,%3$F);relation["leisure"~"^(park|garden)$"](around:%1$d,%2$F,%3$F);'
            .'way["landuse"="grass"](around:%1$d,%2$F,%3$F);relation["landuse"="grass"](around:%1$d,%2$F,%3$F););out geom;',
            $radius, $lat, $lng,
        ), $lat, $lng);
    }

    /** @return list<array<string, mixed>> */
    private function search(string $query, float $lat, float $lng): array
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(60)
                ->retry(3, 15_000, fn ($e) => $e instanceof ConnectionException || in_array($e->response?->status(), [429, 502, 503, 504], true), throw: false)
                ->asForm()
                ->post((string) config('namaa.overpass_url'), ['data' => $query]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Overpass did not answer: '.$e->getMessage(), previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Overpass answered HTTP '.$response->status());
        }

        $out = [];
        foreach ($response->json('elements') ?? [] as $element) {
            $ring = $this->ring($element);
            if ($ring === null) {
                continue;
            }
            [$cLng, $cLat] = $this->centroid($ring);
            $out[] = [
                'osm' => $element['type'].'/'.$element['id'],
                'name' => $element['tags']['name:en'] ?? $element['tags']['name'] ?? null,
                'leisure' => $element['tags']['leisure'] ?? null,
                'tag' => isset($element['tags']['leisure']) ? 'leisure='.$element['tags']['leisure'] : 'landuse='.($element['tags']['landuse'] ?? '?'),
                'polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                'area_m2' => (int) round($this->area($ring)),
                'distance_m' => (int) round($this->distance($lat, $lng, $cLat, $cLng)),
                'contains' => $this->contains($ring, $lng, $lat),
            ];
        }

        usort($out, fn ($a, $b) => [$b['contains'], $a['distance_m']] <=> [$a['contains'], $b['distance_m']]);

        return $out;
    }

    /**
     * The candidate to save: one that contains the marker (the smallest such, so a
     * garden inside a big park wins over the park around it), else none.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    public static function pick(array $candidates): ?array
    {
        $inside = array_values(array_filter($candidates, fn ($c) => $c['contains'] && $c['area_m2'] >= 200));
        usort($inside, fn ($a, $b) => $a['area_m2'] <=> $b['area_m2']);

        return $inside[0] ?? null;
    }

    /** Closed [lng, lat] ring of a way, or of a relation's largest outer member. @return list<array{0: float, 1: float}>|null */
    private function ring(array $element): ?array
    {
        $ways = $element['type'] === 'way'
            ? [$element['geometry'] ?? []]
            : array_map(fn ($m) => $m['geometry'] ?? [], array_filter($element['members'] ?? [], fn ($m) => ($m['type'] ?? '') === 'way' && ($m['role'] ?? 'outer') === 'outer'));

        $best = null;
        foreach ($ways as $geometry) {
            $ring = array_map(fn ($p) => [round((float) $p['lon'], 7), round((float) $p['lat'], 7)], $geometry);
            if (count($ring) < 3) {
                continue;
            }
            if ($ring[0] !== $ring[count($ring) - 1]) {
                $ring[] = $ring[0];
            }
            if (count($ring) >= 4 && ($best === null || $this->area($ring) > $this->area($best))) {
                $best = $ring;
            }
        }

        return $best;
    }

    /** Area in m² (spherical excess on a small polygon, equirectangular projection around its centre). */
    public function area(array $ring): float
    {
        $lat0 = deg2rad(array_sum(array_column($ring, 1)) / count($ring));
        $sum = 0.0;
        for ($i = 0, $n = count($ring) - 1; $i < $n; $i++) {
            [$x1, $y1] = $this->project($ring[$i], $lat0);
            [$x2, $y2] = $this->project($ring[$i + 1], $lat0);
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return abs($sum) / 2;
    }

    /** @return array{0: float, 1: float} metres */
    private function project(array $point, float $lat0): array
    {
        return [deg2rad($point[0]) * self::EARTH_M * cos($lat0), deg2rad($point[1]) * self::EARTH_M];
    }

    /** @return array{0: float, 1: float} [lng, lat] (vertex mean, good enough for a park) */
    private function centroid(array $ring): array
    {
        $points = array_slice($ring, 0, -1);

        return [array_sum(array_column($points, 0)) / count($points), array_sum(array_column($points, 1)) / count($points)];
    }

    private function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_M * asin(min(1, sqrt($a)));
    }

    /** Ray casting, [lng, lat]. */
    private function contains(array $ring, float $lng, float $lat): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
