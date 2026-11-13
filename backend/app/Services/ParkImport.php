<?php

namespace App\Services;

use App\Enums\DataSource;
use App\Enums\GeometrySource;
use App\Enums\MonitoringMode;
use App\Enums\ParkStatus;
use App\Enums\SiteType;
use App\Models\Park;
use App\Models\ParkClaim;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports a park selection file (data/parks/makkah-parks.geojson, or a trimmed OSM export).
 *
 * Rules: new parks are always `satellite_only` (source real); the mode of an existing park is
 * never changed (a different wanted mode is only reported); a `traced` boundary or green zone is
 * never overwritten unless $forceGeometry; claims are written once per unique key; ambiguous
 * name matches are reported, never guessed. Re-running the same file changes nothing.
 */
class ParkImport
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @param  array<string, mixed>  $collection
     * @return list<array{action: string, name: string, slug: ?string, area_m2: ?int, mode: ?string, claims: int, notes: list<string>, park_id: ?int}>
     */
    public function run(array $collection, int $companyId, bool $dryRun = false, bool $forceGeometry = false): array
    {
        $rows = [];
        foreach ($collection['features'] ?? [] as $feature) {
            $rows[] = $this->one($feature, $companyId, $dryRun, $forceGeometry);
        }

        return $rows;
    }

    /** @param array<string, mixed> $feature */
    private function one(array $feature, int $companyId, bool $dryRun, bool $forceGeometry): array
    {
        $p = $feature['properties'] ?? [];
        $osmId = $this->str($p['osm_id'] ?? null);
        $slug = $this->str($p['slug'] ?? null) ?? ($osmId ? 'osm-'.str_replace('/', '-', $osmId) : null);
        $nameEn = $this->str($p['name_en'] ?? $p['name:en'] ?? null) ?? $this->str($p['name'] ?? null);
        $nameAr = $this->str($p['name_ar'] ?? $p['name:ar'] ?? null)
            ?? (preg_match('/\p{Arabic}/u', (string) ($p['name'] ?? '')) ? $this->str($p['name']) : null);
        $label = $nameEn ?? $nameAr ?? ($slug ?? '(unnamed)');
        $row = ['action' => 'skipped', 'name' => $label, 'slug' => $slug, 'area_m2' => null, 'mode' => null, 'claims' => 0, 'notes' => [], 'park_id' => null];

        $boundary = $this->boundary($feature['geometry'] ?? null);
        if ($boundary === null) {
            $row['notes'][] = 'no usable Polygon geometry';

            return $row;
        }
        if ($nameEn === null && $nameAr === null) {
            $row['notes'][] = 'no name';

            return $row;
        }
        $nameEn ??= $nameAr;
        $nameAr ??= $nameEn;
        $slug ??= Str::slug($nameEn) ?: null;
        $row['slug'] = $slug;

        $match = $this->find($companyId, $slug, $osmId, $nameEn, $nameAr);
        if ($match === 'ambiguous') {
            $row['action'] = 'ambiguous';
            $row['notes'][] = 'several parks share this name; add osm_id or slug to the file';

            return $row;
        }
        /** @var Park|null $park */
        $park = $match;

        $area = (int) round(Geo::area($boundary));
        $c = Geo::centroid($boundary);
        $source = GeometrySource::tryFrom((string) ($p['geometry_source'] ?? '')) ?? GeometrySource::Osm;
        $zone = is_array($p['green_zone'] ?? null) ? $p['green_zone'] : null;
        $wanted = MonitoringMode::tryFrom((string) ($p['monitoring_mode'] ?? ''));

        $geometry = [
            'polygon' => $boundary,
            'area_m2' => $area,
            'lat' => round($c[1], 7),
            'lng' => round($c[0], 7),
            'geometry_source' => $source,
            'outline_source' => $source === GeometrySource::Traced ? 'traced' : 'osm',
            'outline_reference' => $osmId,
        ];
        if ($zone !== null) {
            $geometry['green_zone'] = $zone;
            $geometry['green_zone_area_m2'] = (int) round(Geo::area($zone));
        }

        $claims = array_values(array_filter($p['claims'] ?? [], fn ($c) => is_array($c)
            && in_array($c['field'] ?? null, ParkClaim::FIELDS, true) && filled($c['value'] ?? null) && filled($c['source_name'] ?? null)));

        if ($park === null) {
            $row['action'] = 'create';
            $row['area_m2'] = $area;
            $row['mode'] = MonitoringMode::SatelliteOnly->value;
            $row['claims'] = count($claims);
            if ($wanted && $wanted !== MonitoringMode::SatelliteOnly) {
                $row['notes'][] = "wanted: {$wanted->value} -> run set-mode";
            }
            if (! $dryRun) {
                $created = DB::transaction(function () use ($companyId, $slug, $osmId, $nameEn, $nameAr, $p, $geometry, $claims) {
                    $park = new Park([
                        'company_id' => $companyId, 'name_en' => $nameEn, 'name_ar' => $nameAr,
                        'district' => $this->str($p['district'] ?? null), 'slug' => $slug, 'osm_id' => $osmId,
                        'source' => DataSource::Real, 'site_type' => SiteType::Park,
                        'monitoring_mode' => MonitoringMode::SatelliteOnly, 'status' => ParkStatus::NoSensor,
                        'geometry_updated_at' => now(), 'outline_at' => now(), 'area_source' => $geometry['outline_source'],
                        'area_reference' => 'Computed from the boundary (geodesic area)',
                        ...$geometry,
                    ]);
                    $park->save();
                    $this->claims($park, $claims, false);
                    $this->logger->log('park.imported', $park, ['after' => ['slug' => $park->slug, 'osm_id' => $park->osm_id, 'area_m2' => $park->area_m2, 'monitoring_mode' => 'satellite_only']]);

                    return $park;
                });
                $row['park_id'] = $created->id;
            }

            return $row;
        }

        $row['park_id'] = $park->id;
        $row['mode'] = $park->monitoring_mode->value;
        $row['area_m2'] = $park->area_m2;
        if ($wanted && $wanted !== $park->monitoring_mode) {
            $row['notes'][] = "wanted: {$wanted->value} -> run set-mode";
        }

        $changes = [];
        $protected = $park->geometry_source === GeometrySource::Traced && ! $forceGeometry;
        if ($protected) {
            $row['notes'][] = 'traced geometry kept (use --force-geometry)';
        } else {
            foreach ($geometry as $key => $value) {
                if (! $this->same($park->{$key}, $value)) {
                    $changes[$key] = $value;
                }
            }
            if (isset($changes['polygon']) || isset($changes['green_zone'])) {
                $changes['geometry_updated_at'] = now();
                $changes['outline_at'] = now();
            }
            if (isset($changes['area_m2'])) {
                $row['area_m2'] = $area;
            }
        }
        // Fill identifiers and the district when the park has none (never overwrite).
        if ($park->osm_id === null && $osmId && ! Park::where('osm_id', $osmId)->exists()) {
            $changes['osm_id'] = $osmId;
        }
        if ($park->slug === null && $slug && ! Park::where('slug', $slug)->exists()) {
            $changes['slug'] = $slug;
        }
        if ($park->district === null && $this->str($p['district'] ?? null)) {
            $changes['district'] = $this->str($p['district']);
        }

        $newClaims = $this->claims($park, $claims, true);
        $row['claims'] = $newClaims;
        $row['action'] = $changes !== [] || $newClaims > 0 ? 'update' : 'unchanged';

        if (! $dryRun && $row['action'] === 'update') {
            DB::transaction(function () use ($park, $changes, $claims, $forceGeometry) {
                $before = array_intersect_key($park->only(array_keys($changes)), $changes);
                if ($changes !== []) {
                    $park->forceFill($changes)->save();
                    $this->logger->log('park.import_updated', $park, [
                        'before' => array_diff_key($before, ['polygon' => 1, 'green_zone' => 1]),
                        'after' => array_diff_key($changes, ['polygon' => 1, 'green_zone' => 1]) + ['forced' => $forceGeometry],
                    ]);
                }
                $this->claims($park, $claims, false);
            });
        }

        return $row;
    }

    /** Count (countOnly) or write the claims that are not yet there. @param list<array<string, mixed>> $claims */
    private function claims(Park $park, array $claims, bool $countOnly): int
    {
        $new = 0;
        foreach ($claims as $c) {
            $key = ['park_id' => $park->id, 'field' => $c['field'], 'value' => (string) $c['value'], 'source_name' => $c['source_name']];
            if (ParkClaim::where($key)->exists()) {
                continue;
            }
            $new++;
            if (! $countOnly) {
                ParkClaim::create($key + [
                    'source_url' => $c['source_url'] ?? null,
                    'source_date' => $c['source_date'] ?? null,
                    'note' => $c['note'] ?? null,
                ]);
            }
        }

        return $new;
    }

    /** @return Park|'ambiguous'|null */
    private function find(int $companyId, ?string $slug, ?string $osmId, string $nameEn, string $nameAr): Park|string|null
    {
        $parks = Park::forCompany($companyId)->where('site_type', SiteType::Park->value);

        if ($slug && ($p = (clone $parks)->where('slug', $slug)->first())) {
            return $p;
        }
        if ($osmId && ($p = (clone $parks)->where('osm_id', $osmId)->first())) {
            return $p;
        }
        $byName = (clone $parks)->whereNull('osm_id')
            ->where(fn ($q) => $q->whereRaw('lower(name_en) = ?', [mb_strtolower($nameEn)])->orWhere('name_ar', $nameAr))
            ->get();

        return $byName->count() > 1 ? 'ambiguous' : $byName->first();
    }

    /** Polygon, or the largest polygon of a MultiPolygon. @param array<string, mixed>|null $g @return array<string, mixed>|null */
    private function boundary(?array $g): ?array
    {
        if (($g['type'] ?? null) === 'Polygon' && ! empty($g['coordinates'])) {
            return $g;
        }
        if (($g['type'] ?? null) === 'MultiPolygon' && ! empty($g['coordinates'])) {
            $polys = array_map(fn ($c) => ['type' => 'Polygon', 'coordinates' => $c], $g['coordinates']);
            usort($polys, fn ($a, $b) => Geo::area($b) <=> Geo::area($a));

            return $polys[0];
        }

        return null;
    }

    private function same(mixed $current, mixed $want): bool
    {
        if ($current instanceof \BackedEnum) {
            $current = $current->value;
        }
        if ($want instanceof \BackedEnum) {
            $want = $want->value;
        }
        if (is_array($want) || is_array($current)) {
            return json_encode($current) === json_encode($want);
        }
        if (is_numeric($current) && is_numeric($want)) {
            return abs((float) $current - (float) $want) < 1e-7;
        }

        return $current === $want;
    }

    private function str(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return $v === '' ? null : $v;
    }
}
