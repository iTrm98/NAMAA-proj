<?php

namespace App\Console\Commands;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Services\ActivityLogger;
use Database\Seeders\DemoAccountsSeeder;
use Database\Seeders\DemoParksSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The test bench: park "Workshop (test bench)" (is_test, hidden from lists unless an admin
 * asks) with Bench A (sensor, pump, flow), Bench B (sensor) and Bench Cam (browser camera).
 * Idempotent. New device tokens are printed once here and appended to the tokens file;
 * existing nodes and lines are never touched.
 */
#[Signature('namaa:bench:setup')]
#[Description('Create the test bench park and its nodes (idempotent). Tokens are printed once.')]
class BenchSetup extends Command
{
    public const PARK_EN = 'Workshop (test bench)';

    /** @var list<array{name: string, type: NodeType, pump: bool}> */
    private const NODES = [
        ['name' => 'Bench A', 'type' => NodeType::Sensor, 'pump' => true],
        ['name' => 'Bench B', 'type' => NodeType::Sensor, 'pump' => false],
        ['name' => 'Bench Cam', 'type' => NodeType::Camera, 'pump' => false],
    ];

    public function handle(ActivityLogger $logger): int
    {
        $company = Company::where('name', DemoAccountsSeeder::COMPANY_NAME)->first() ?? Company::orderBy('id')->first();
        if (! $company) {
            $this->error('No company found. Seed the demo data first.');

            return self::FAILURE;
        }

        $park = \App\Services\StandSetup::find($company->id); // by site_type, then the Workshop name
        if (! $park) {
            // Next to King Abdullah Park (the real park); a 400 m2 outline.
            $ref = Park::where('company_id', $company->id)->where('name_en', 'King Abdullah Park')->first();
            [$lat, $lng] = $ref ? [$ref->lat + 0.0004, $ref->lng + 0.0004] : [21.4225, 39.8262];
            $park = Park::create([
                'company_id' => $company->id, 'name_ar' => 'الورشة (منصة الاختبار)', 'name_en' => self::PARK_EN,
                'district' => 'Test bench', 'area_m2' => 400,
                'polygon' => DemoParksSeeder::rectangle($lat, $lng, 400), 'lat' => $lat, 'lng' => $lng,
                'moisture_min' => 25, 'moisture_max' => 70, 'auto_irrigation' => false,
                'status' => ParkStatus::Offline, 'source' => DataSource::Real, 'is_test' => true,
            ]);
            $logger->log('park.created', $park, ['after' => ['name_en' => self::PARK_EN, 'is_test' => true]], companyId: $company->id);
        }

        $created = [];
        foreach (self::NODES as $spec) {
            if (Node::where('park_id', $park->id)->where('name', $spec['name'])->exists()) {
                continue;
            }
            $camera = $spec['type'] === NodeType::Camera;
            $node = Node::create([
                'park_id' => $park->id, 'type' => $spec['type'], 'name' => $spec['name'],
                'has_pump' => $spec['pump'], 'pump_target' => false, 'source' => DataSource::Real,
                'capture_mode' => $camera ? 'browser' : 'url',
            ]);
            $logger->log('node.created', $node, ['after' => ['name' => $node->name, 'type' => $node->type->value, 'park_id' => $park->id]], companyId: $company->id);

            $token = null;
            if (! $camera) {
                $token = $node->createToken('device', ['device'])->plainTextToken;
                SimCamera::appendTokenLine([$node->id, $node->name, $park->name_en, 'real', $token], (string) config('namaa.node_tokens_file'));
            }
            $created[] = [$node->id, $node->name, $token ?? '(no token: browser camera)'];
        }

        if ($created === []) {
            $this->info('Nothing to do: the test bench already exists.');

            return self::SUCCESS;
        }

        $this->info('Test bench ready. Tokens are shown once (also appended to storage/app/private/node-tokens.txt):');
        $this->table(['Node ID', 'Node', 'Token'], $created);

        return self::SUCCESS;
    }
}
