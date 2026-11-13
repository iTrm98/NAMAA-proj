<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\ParkImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;

/**
 * Import a park selection file. New parks are always satellite_only; the mode of an existing
 * park is never changed (use namaa:parks:set-mode); a traced boundary or green zone is kept
 * unless --force-geometry. --dry-run prints the table and changes nothing.
 */
#[Signature('namaa:parks:import {file : GeoJSON FeatureCollection (data/parks/makkah-parks.geojson)} {--dry-run} {--force-geometry : overwrite traced boundaries and green zones} {--company= : company id (default: the first company)}')]
#[Description('Import parks from a GeoJSON selection file (create / update / unchanged table; --dry-run changes nothing).')]
class ImportParksCommand extends Command
{
    public function handle(ParkImport $import): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }
        try {
            $collection = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Invalid JSON: '.$e->getMessage());

            return self::FAILURE;
        }
        if (($collection['type'] ?? null) !== 'FeatureCollection') {
            $this->error('The file is not a GeoJSON FeatureCollection.');

            return self::FAILURE;
        }

        $companyId = $this->option('company') ? (int) $this->option('company') : Company::query()->orderBy('id')->value('id');
        if (! $companyId || ! Company::whereKey($companyId)->exists()) {
            $this->error('No such company.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $rows = $import->run($collection, (int) $companyId, $dry, (bool) $this->option('force-geometry'));

        $this->info(($dry ? 'DRY RUN, nothing written. ' : '')."Company {$companyId}.");
        $this->table(['Action', 'Park', 'Slug', 'Area m2', 'Mode', 'New claims', 'Notes'], array_map(fn ($r) => [
            $r['action'], $r['name'], $r['slug'] ?? '-', $r['area_m2'] ?? '-', $r['mode'] ?? '-', $r['claims'], implode('; ', $r['notes']),
        ], $rows));
        $counts = array_count_values(array_column($rows, 'action'));
        $this->line(collect(['create', 'update', 'unchanged', 'ambiguous', 'skipped'])->map(fn ($a) => "{$a}: ".($counts[$a] ?? 0))->implode(', '));

        return self::SUCCESS;
    }
}
