<?php

namespace Database\Seeders;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\ParkType;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The five demo parks from data/seed/parks.json, their nodes, and one Sanctum
 * token (ability "device") per sensor node.
 *
 * Tokens are printed once and written to storage/app/private/node-tokens.txt
 * (git-ignored). They are never written to a tracked file.
 */
class DemoParksSeeder extends Seeder
{
    /** Assumption for impact numbers: a fixed schedule uses 4 L per m2 per day. */
    public const BASELINE_L_PER_M2_PER_DAY = 4;

    public const TOKEN_FILE = 'app/private/node-tokens.txt';

    public function run(): void
    {
        $company = Company::where('name', DemoAccountsSeeder::COMPANY_NAME)->firstOrFail();
        $tokens = [];

        foreach (self::parks() as $data) {
            // Parks of the second demo company are created by php artisan namaa:demo:second-company.
            if (($data['company'] ?? null) === 'second') {
                continue;
            }
            $source = DataSource::from($data['source']);

            $park = Park::create(self::dataSources($data) + [
                'company_id' => $company->id,
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'district' => $data['district'],
                'park_type' => ParkType::from($data['park_type'] ?? 'public_park'),
                'area_m2' => $data['area_m2'],
                'polygon' => $data['polygon'] ?? self::rectangle($data['lat'], $data['lng'], $data['area_m2']),
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'moisture_min' => 25,
                'moisture_max' => 70,
                // Assumption: 4 L per m2 per day on a fixed schedule (impact "water saved" only).
                'baseline_l_per_day' => $data['area_m2'] * self::BASELINE_L_PER_M2_PER_DAY,
                // Real parks stay offline until their boards report; DemoHistorySeeder
                // sets the status of the simulated parks from their latest readings.
                'status' => ParkStatus::Offline,
                'source' => $source,
            ]);

            foreach ($data['nodes'] as $nodeData) {
                $node = Node::create([
                    'park_id' => $park->id,
                    'type' => NodeType::from($nodeData['type']),
                    'name' => $nodeData['name'],
                    'has_pump' => (bool) ($nodeData['has_pump'] ?? false),
                    'pump_target' => false,
                    'source' => $source,
                    // Camera 1 (King Abdullah): the laptop's camera feeds it from the browser.
                    'capture_mode' => $source === DataSource::Real && $nodeData['type'] === 'camera' ? 'browser' : 'url',
                ]);

                // Only sensor nodes post readings. The camera is polled by Laravel.
                if ($node->type === NodeType::Sensor) {
                    $tokens[] = [
                        $node->id,
                        $node->name,
                        $park->name_en,
                        $source->value,
                        $node->createToken('device', ['device'])->plainTextToken,
                    ];
                }
            }
        }

        $this->reportTokens($tokens);
    }

    /**
     * Data sources of the outline and the area (Sprint 3.1): from `outline_source` /
     * `area_source` ({type, reference, by, at}) when the file has them, else from the
     * older polygon_approximate / polygon_source. "by" is a name in the file; the
     * seeded row keeps it in the reference (no user id on a fresh database).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function dataSources(array $data): array
    {
        $approximate = ($data['polygon_approximate'] ?? true) || empty($data['polygon']);
        $fallback = $approximate
            ? ['type' => 'approximate', 'reference' => 'Rectangle of the estimated area around the park centre (seed data), not verified']
            : ['type' => str_starts_with((string) ($data['polygon_source'] ?? ''), 'OpenStreetMap') ? 'osm' : 'traced', 'reference' => $data['polygon_source'] ?? null];

        $row = [];
        foreach (['outline' => $fallback, 'area' => $approximate ? ['type' => 'approximate', 'reference' => 'Estimate (seed data), not verified'] : $fallback] as $field => $default) {
            $s = ($data["{$field}_source"] ?? null) ?: $default;
            $reference = $s['reference'] ?? null;
            if (! empty($s['by'])) {
                $reference = trim(($reference ? "$reference; " : '').'by '.$s['by']);
            }
            $row["{$field}_source"] = $s['type'] ?? 'approximate';
            $row["{$field}_reference"] = $reference;
            $row["{$field}_at"] = $s['at'] ?? null;
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public static function parks(): array
    {
        $path = base_path('../data/seed/parks.json');

        if (! is_file($path)) {
            throw new RuntimeException("Missing {$path}. docker-compose.yml mounts ./data at /var/www/data.");
        }

        return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR)['parks'];
    }

    /**
     * Approximate outline: a 3:2 rectangle of the given area around the centre.
     * GeoJSON Polygon, closed ring, coordinates [lng, lat].
     */
    public static function rectangle(float $lat, float $lng, int $areaM2): array
    {
        $widthM = sqrt($areaM2 * 1.5);
        $heightM = $areaM2 / $widthM;
        $dLat = ($heightM / 2) / 111_320;
        $dLng = ($widthM / 2) / (111_320 * cos(deg2rad($lat)));

        $west = round($lng - $dLng, 7);
        $east = round($lng + $dLng, 7);
        $south = round($lat - $dLat, 7);
        $north = round($lat + $dLat, 7);

        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$west, $south], [$east, $south], [$east, $north], [$west, $north], [$west, $south],
            ]],
        ];
    }

    /** @param list<array{int, string, string, string, string}> $tokens */
    private function reportTokens(array $tokens): void
    {
        $headers = ['Node ID', 'Node', 'Park', 'Source', 'Token (ability: device)'];

        $this->command?->newLine();
        $this->command?->info('Node tokens (shown once; also saved to storage/'.self::TOKEN_FILE.'):');
        $this->command?->table($headers, $tokens);

        // Tests use their own throwaway tokens: never overwrite the real file.
        if (app()->runningUnitTests()) {
            return;
        }

        $lines = [
            'NAMAA node tokens, created '.now()->toIso8601String().' by php artisan db:seed.',
            'Copy the node ID and token of a real board into firmware/secrets.h. Never commit them.',
            'A new migrate:fresh --seed replaces every token.',
            '',
            implode("\t", $headers),
        ];
        foreach ($tokens as $row) {
            $lines[] = implode("\t", $row);
        }

        File::put(storage_path(self::TOKEN_FILE), implode(PHP_EOL, $lines).PHP_EOL);
    }
}
