<?php

namespace App\Console\Commands;

use App\Services\OsmParks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/** Parks and gardens of Makkah from OpenStreetMap into data/parks/osm/. Writes a file only; no database. */
#[Signature('namaa:parks:fetch-osm')]
#[Description('Fetch leisure=park/garden for Makkah from Overpass into data/parks/osm/makkah-osm-YYYYMMDD.geojson (no database writes).')]
class FetchOsmParksCommand extends Command
{
    public function handle(OsmParks $osm): int
    {
        try {
            $collection = $osm->fetch();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = OsmParks::dir().'/osm';
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create {$dir}");

            return self::FAILURE;
        }
        $file = $dir.'/makkah-osm-'.now()->format('Ymd').'.geojson';
        file_put_contents($file, json_encode($collection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $s = OsmParks::summary($collection);
        $this->info("Wrote {$file}");
        $this->line("Features: {$s['features']}");
        $this->line("At least 1 ha: {$s['ge_1ha']}");
        $this->line("With Arabic names: {$s['arabic']}");
        if ($s['juranah'] === []) {
            $this->warn("Ju'ranah: no match in the export.");
        } else {
            foreach ($s['juranah'] as $m) {
                $this->line("Ju'ranah match: {$m['osm_id']} \"{$m['name']}\" ({$m['area_m2']} m2)");
            }
        }

        return self::SUCCESS;
    }
}
