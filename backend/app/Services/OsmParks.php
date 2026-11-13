<?php

namespace App\Services;

use App\Support\Geo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Parks and gardens of Makkah from OpenStreetMap (Overpass, `out geom`) as a GeoJSON
 * FeatureCollection, plus the files under data/parks. Fetching never touches the database.
 */
class OsmParks
{
    public const BBOX = '21.10,39.50,21.80,40.20';

    public const JURANAH = "/جعرانة|Ju'?ranah|Jaranah|Ji'?ranah/iu";

    public static function dir(): string
    {
        return rtrim((string) config('namaa.parks_data_path'), '/\\');
    }

    public static function selectionPath(): string
    {
        return self::dir().'/makkah-parks.geojson';
    }

    /** @return array<string, mixed> a FeatureCollection with `fetched_at` */
    public function fetch(): array
    {
        $bbox = self::BBOX;
        $query = "[out:json][timeout:180];(way[\"leisure\"~\"^(park|garden)\$\"]($bbox);relation[\"leisure\"~\"^(park|garden)\$\"]($bbox););out geom;";

        try {
            $response = Http::withUserAgent(ParkOutlines::USER_AGENT)
                ->timeout(180)
                ->retry(2, 10_000, throw: false)
                ->asForm()
                ->post((string) config('namaa.overpass_url'), ['data' => $query]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Overpass did not answer: '.$e->getMessage(), previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Overpass answered HTTP '.$response->status());
        }

        $features = [];
        foreach ($response->json('elements') ?? [] as $element) {
            $geometry = $this->geometry($element);
            if ($geometry === null) {
                continue;
            }
            $tags = $element['tags'] ?? [];
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'osm_id' => $element['type'].'/'.$element['id'],
                    'name' => $tags['name'] ?? null,
                    'name:ar' => $tags['name:ar'] ?? null,
                    'name:en' => $tags['name:en'] ?? null,
                    'area_m2' => (int) round(Geo::area($geometry)),
                ],
                'geometry' => $geometry,
            ];
        }

        return ['type' => 'FeatureCollection', 'fetched_at' => now()->toIso8601String(), 'features' => $features];
    }

    /** @param array<string, mixed> $collection @return array{features: int, ge_1ha: int, arabic: int, juranah: list<array<string, mixed>>} */
    public static function summary(array $collection): array
    {
        $features = $collection['features'] ?? [];
        $juranah = [];
        $arabic = 0;
        foreach ($features as $f) {
            $p = $f['properties'] ?? [];
            $names = array_filter([$p['name'] ?? null, $p['name:ar'] ?? null, $p['name:en'] ?? null]);
            if (($p['name:ar'] ?? null) || preg_match('/\p{Arabic}/u', (string) ($p['name'] ?? ''))) {
                $arabic++;
            }
            foreach ($names as $n) {
                if (preg_match(self::JURANAH, $n)) {
                    $juranah[] = ['osm_id' => $p['osm_id'] ?? null, 'name' => $n, 'area_m2' => $p['area_m2'] ?? null];
                    break;
                }
            }
        }

        return [
            'features' => count($features),
            'ge_1ha' => count(array_filter($features, fn ($f) => ($f['properties']['area_m2'] ?? 0) >= 10_000)),
            'arabic' => $arabic,
            'juranah' => $juranah,
        ];
    }

    /** Newest data/parks/osm/makkah-osm-*.geojson path, or null. */
    public static function newestExport(): ?string
    {
        $files = glob(self::dir().'/osm/makkah-osm-*.geojson') ?: [];
        sort($files);

        return $files === [] ? null : end($files);
    }

    /** @param array<string, mixed> $element @return array<string, mixed>|null Polygon or MultiPolygon */
    private function geometry(array $element): ?array
    {
        if ($element['type'] === 'way') {
            $ring = $this->ring($element['geometry'] ?? []);

            return $ring === null ? null : ['type' => 'Polygon', 'coordinates' => [$ring]];
        }

        $outer = $inner = [];
        foreach ($element['members'] ?? [] as $m) {
            if (($m['type'] ?? '') !== 'way' || empty($m['geometry'])) {
                continue;
            }
            $line = array_map(fn ($p) => [round((float) $p['lon'], 7), round((float) $p['lat'], 7)], $m['geometry']);
            if (($m['role'] ?? 'outer') === 'inner') {
                $inner[] = $line;
            } else {
                $outer[] = $line;
            }
        }
        $outers = $this->stitch($outer);
        if ($outers === []) {
            return null;
        }
        $polygons = array_map(fn ($r) => [$r], $outers);
        foreach ($this->stitch($inner) as $hole) {
            foreach ($polygons as $i => $polygon) {
                if (Geo::pointInPolygon($hole[0][0], $hole[0][1], ['type' => 'Polygon', 'coordinates' => [$polygon[0]]])) {
                    $polygons[$i][] = $hole;
                    break;
                }
            }
        }

        return count($polygons) === 1
            ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }

    /** @param list<array{lat: float, lon: float}> $points @return list<array{0: float, 1: float}>|null */
    private function ring(array $points): ?array
    {
        $ring = array_map(fn ($p) => [round((float) $p['lon'], 7), round((float) $p['lat'], 7)], $points);
        if (count($ring) < 3) {
            return null;
        }
        if ($ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return count($ring) >= 4 ? $ring : null;
    }

    /**
     * Join way segments end to end into closed rings; open leftovers are dropped.
     *
     * @param  list<list<array{0: float, 1: float}>>  $lines
     * @return list<list<array{0: float, 1: float}>>
     */
    private function stitch(array $lines): array
    {
        $rings = [];
        while ($lines !== []) {
            $current = array_shift($lines);
            while ($current[0] !== $current[count($current) - 1]) {
                $end = $current[count($current) - 1];
                $found = false;
                foreach ($lines as $i => $line) {
                    if ($line[0] === $end) {
                        $current = array_merge($current, array_slice($line, 1));
                    } elseif ($line[count($line) - 1] === $end) {
                        $current = array_merge($current, array_slice(array_reverse($line), 1));
                    } else {
                        continue;
                    }
                    unset($lines[$i]);
                    $lines = array_values($lines);
                    $found = true;
                    break;
                }
                if (! $found) {
                    continue 2;
                }
            }
            if (count($current) >= 4) {
                $rings[] = $current;
            }
        }

        return $rings;
    }
}
