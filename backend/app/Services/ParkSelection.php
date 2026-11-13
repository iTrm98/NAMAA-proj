<?php

namespace App\Services;

use App\Enums\MonitoringMode;
use App\Enums\SiteType;
use App\Models\Park;

/** Reads and writes the selection file data/parks/makkah-parks.geojson and enforces the simulated-park cap. */
class ParkSelection
{
    /** @return array<string, mixed> */
    public function read(): array
    {
        $path = OsmParks::selectionPath();
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) && isset($data['features']) ? $data : ['type' => 'FeatureCollection', 'features' => []];
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>
     */
    public function write(array $features): array
    {
        $path = OsmParks::selectionPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $collection = ['type' => 'FeatureCollection', 'features' => array_values($features)];
        $tmp = $path.'.tmp';
        file_put_contents($tmp, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n");
        rename($tmp, $path);

        return $collection;
    }

    /**
     * Simulated parks the company would have: its existing simulated parks plus the selected
     * features marked simulated that are not one of those parks already (matched by slug / osm_id).
     *
     * @param  list<array<string, mixed>>  $features
     */
    public function simulatedCount(int $companyId, array $features): int
    {
        $existing = Park::forCompany($companyId)->where('site_type', SiteType::Park->value)->whereNull('archived_at')
            ->where('monitoring_mode', MonitoringMode::Simulated->value)->get(['slug', 'osm_id']);
        $slugs = $existing->pluck('slug')->filter()->all();
        $osm = $existing->pluck('osm_id')->filter()->all();

        $extra = 0;
        foreach ($features as $f) {
            $p = $f['properties'] ?? [];
            if (($p['monitoring_mode'] ?? null) !== MonitoringMode::Simulated->value) {
                continue;
            }
            if (in_array($p['slug'] ?? null, $slugs, true) || in_array($p['osm_id'] ?? null, $osm, true)) {
                continue;
            }
            $extra++;
        }

        return $existing->count() + $extra;
    }
}
