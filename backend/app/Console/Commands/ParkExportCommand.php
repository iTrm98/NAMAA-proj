<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Park;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Write the parks as they are in the database back to data/seed/parks.json, so a
 * fresh seed rebuilds them (Sprint 3.1). It keeps the file's structure: the notes,
 * each park's key and scenario, the key order, one-line polygons and nodes. It
 * updates names, district, coordinates, area, outline (only when it is not
 * approximate: the seeder draws that rectangle itself), both data sources and the
 * nodes; a park that is not in the file yet is added with a new key (the second
 * demo company's park gets "company": "second"). Parks are matched by English name.
 *
 * data/ is mounted read-only in the containers, so by default the result goes to
 * storage/app/parks.export.json, to be copied over on the host.
 */
#[Signature('namaa:parks:export {--path= : output file (default: data/seed/parks.json when writable, else storage/app/parks.export.json)}')]
#[Description('Export the parks (outlines, areas, data sources, nodes) to data/seed/parks.json, keeping its structure and keys.')]
class ParkExportCommand extends Command
{
    public const SOURCES_NOTE = 'outline_source / area_source: where the outline and the area come from: {type: official | traced | osm | approximate, reference, by (a name), at (date)}. "company": "second" = the park of the second demo company, created by php artisan namaa:demo:second-company (the seeder skips it). Written by php artisan namaa:parks:export.';

    public function handle(): int
    {
        $seedPath = (string) config('namaa.parks_seed_path', base_path('../data/seed/parks.json'));
        $file = is_file($seedPath) ? json_decode((string) file_get_contents($seedPath), true, flags: JSON_THROW_ON_ERROR) : ['notes' => [], 'parks' => []];
        $byName = collect($file['parks'])->keyBy('name_en');
        $second = Company::where('name', DemoSecondCompany::COMPANY_NAME)->value('id');

        $parks = Park::with(['nodes' => fn ($q) => $q->orderBy('id'), 'outlineBy:id,name', 'areaBy:id,name'])->orderBy('id')->get();
        $out = [];
        $added = [];
        foreach ($parks as $park) {
            $old = $byName->get($park->name_en);
            if (! $old) {
                $added[] = $park->name_en;
            }
            $out[] = self::entry($park, $old ?? [], $second !== null && $park->company_id === $second);
        }
        // Parks only in the file (not in this database) stay as they are.
        $inDb = $parks->pluck('name_en')->all();
        foreach ($file['parks'] as $p) {
            if (! in_array($p['name_en'], $inDb, true)) {
                $out[] = $p;
            }
        }

        $notes = $file['notes'] ?? [];
        if (! in_array(self::SOURCES_NOTE, $notes, true)) {
            $notes[] = self::SOURCES_NOTE;
        }
        $text = self::format(['notes' => $notes, 'parks' => $out]);

        $target = (string) ($this->option('path') ?: (is_writable($seedPath) ? $seedPath : storage_path('app/parks.export.json')));
        file_put_contents($target, $text);

        $this->info(sprintf('Exported %d park(s) to %s%s.', count($out), $target, $added ? ' (new: '.implode(', ', $added).')' : ''));
        if ($target !== $seedPath && ! $this->option('path')) {
            $this->warn('data/seed/parks.json is read-only here. On the host, copy backend/storage/app/parks.export.json to data/seed/parks.json.');
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $old  the park's current entry in the file @return array<string, mixed> */
    public static function entry(Park $park, array $old, bool $secondCompany): array
    {
        $approximate = self::type($park->outline_source) === 'approximate';
        $e = ['key' => $old['key'] ?? Str::snake(Str::of($park->name_en)->replaceMatches('/\b(park|garden)\b/i', '')->trim()->toString()) ?: 'park_'.$park->id];
        if ($secondCompany || ($old['company'] ?? null)) {
            $e['company'] = $old['company'] ?? 'second';
        }
        $e += [
            'name_en' => $park->name_en,
            'name_ar' => $park->name_ar,
            'district' => $park->district,
            'lat' => (float) $park->lat,
            'lng' => (float) $park->lng,
            'park_type' => $park->park_type instanceof \BackedEnum ? $park->park_type->value : $park->park_type,
            'area_m2' => (int) $park->area_m2,
            'source' => $park->source instanceof \BackedEnum ? $park->source->value : $park->source,
            'scenario' => $old['scenario'] ?? 'healthy',
            'polygon_approximate' => $approximate,
        ];
        if (! $approximate) {
            $e['polygon_source'] = $park->outline_reference;
        }
        $e['outline_source'] = self::source($park->outline_source, $park->outline_reference, $park->outlineBy?->name, $park->outline_at);
        $e['area_source'] = self::source($park->area_source, $park->area_reference, $park->areaBy?->name, $park->area_at);
        if (! $approximate) {
            $e['polygon'] = $park->polygon;
        }
        $e['nodes'] = $park->nodes->map(fn ($n) => [
            'name' => $n->name, 'type' => $n->type instanceof \BackedEnum ? $n->type->value : $n->type, 'has_pump' => (bool) $n->has_pump,
        ])->all();

        return $e;
    }

    /** @return array{type: string, reference: ?string, by: ?string, at: ?string} */
    private static function source(mixed $type, ?string $reference, ?string $by, mixed $at): array
    {
        // The seeder keeps a file "by" in the reference ("…; by Turki"): do not repeat it.
        if ($by !== null && $reference !== null) {
            $reference = preg_replace('/(;\s*)?by '.preg_quote($by, '/').'$/', '', $reference) ?: null;
        }

        return ['type' => self::type($type), 'reference' => $reference, 'by' => $by,
            'at' => $at ? \Illuminate\Support\Carbon::parse($at)->utc()->toDateString() : null];
    }

    private static function type(mixed $type): string
    {
        return $type instanceof \BackedEnum ? (string) $type->value : (string) ($type ?: 'approximate');
    }

    /** The file's style: two-space indent, polygons and nodes on one line each. */
    public static function format(array $data): string
    {
        $j = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $inline = fn ($v) => preg_replace(['/,(?=["\[{\-\d])/', '/":(?=[^\s])/'], [', ', '": '], $j($v));

        $lines = ['{', '  "notes": ['];
        foreach ($data['notes'] as $i => $note) {
            $lines[] = '    '.$j($note).($i < count($data['notes']) - 1 ? ',' : '');
        }
        $lines[] = '  ],';
        $lines[] = '  "parks": [';
        foreach ($data['parks'] as $pi => $park) {
            $lines[] = '    {';
            $keys = array_keys($park);
            foreach ($keys as $ki => $key) {
                $comma = $ki < count($keys) - 1 ? ',' : '';
                $value = $park[$key];
                if ($key === 'nodes') {
                    $lines[] = '      "nodes": [';
                    foreach ($value as $ni => $node) {
                        $lines[] = '        { '.substr($inline($node), 1, -1).' }'.($ni < count($value) - 1 ? ',' : '');
                    }
                    $lines[] = '      ]'.$comma;
                } elseif (is_array($value)) {
                    $lines[] = '      '.$j($key).': '.$inline($value).$comma;
                } else {
                    $lines[] = '      '.$j($key).': '.$j($value).$comma;
                }
            }
            $lines[] = '    }'.($pi < count($data['parks']) - 1 ? ',' : '');
        }
        $lines[] = '  ]';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }
}
