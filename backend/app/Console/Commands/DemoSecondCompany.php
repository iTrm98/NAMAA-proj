<?php

namespace App\Console\Commands;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\ParkType;
use App\Enums\UserRole;
use App\Events\ParkStatusChanged;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ParkOutline;
use App\Support\Realtime;
use Database\Seeders\DemoParksSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A second maintenance company for the municipality view (Sprint 3.1): "Al Safwa
 * Gardens Co. (demo)" with an admin and a worker, and a new simulated park of its
 * own: Al Masfalah Park, a real public park in Makkah (OpenStreetMap way/457991700),
 * with one simulated sensor node and a pump ("Sim 4", automatic irrigation on).
 * No existing park is moved. Safe to run again.
 *
 * The park comes from the "al_masfalah" entry of data/seed/parks.json when it is
 * there (so an outline corrected in the editor and exported travels with the
 * repository), else from the built-in OSM definition below.
 */
#[Signature('namaa:demo:second-company')]
#[Description('Create "Al Safwa Gardens Co. (demo)" (admin2@, worker3@) with its own simulated park, Al Masfalah Park.')]
class DemoSecondCompany extends Command
{
    public const COMPANY_NAME = 'Al Safwa Gardens Co. (demo)';

    public const ADMIN_EMAIL = 'admin2@namaa.test';

    public const WORKER_EMAIL = 'worker3@namaa.test';

    public const PARK_KEY = 'al_masfalah';

    public const NODE_NAME = 'Sim 4';

    /** Built-in definition (OpenStreetMap way/457991700, ODbL). @return array<string, mixed> */
    public static function builtInPark(): array
    {
        return [
            'key' => self::PARK_KEY,
            'company' => 'second',
            'name_en' => 'Al Masfalah Park',
            'name_ar' => 'حديقة المسفلة',
            'district' => 'Al Masfalah',
            'lat' => 21.40795,
            'lng' => 39.81945,
            'park_type' => 'public_park',
            'source' => 'simulated',
            'scenario' => 'healthy',
            'polygon_approximate' => false,
            'polygon_source' => 'OpenStreetMap way/457991700 (ODbL)',
            'outline_source' => ['type' => 'osm', 'reference' => 'OpenStreetMap way/457991700 (ODbL)', 'by' => null, 'at' => '2026-11-10'],
            'area_source' => ['type' => 'osm', 'reference' => 'Computed from the OpenStreetMap outline (way/457991700)', 'by' => null, 'at' => '2026-11-10'],
            'polygon' => ['type' => 'Polygon', 'coordinates' => [[
                [39.8190998, 21.4083661], [39.8193399, 21.4083974], [39.8198344, 21.4081247], [39.819962, 21.4081362],
                [39.8200328, 21.407735], [39.8193831, 21.4073112], [39.8187877, 21.4076018], [39.8190998, 21.4083661],
            ]]],
            'nodes' => [['name' => self::NODE_NAME, 'type' => 'sensor', 'has_pump' => true]],
        ];
    }

    /** The park definition: data/seed/parks.json first, else the built-in one. @return array<string, mixed> */
    public static function parkDefinition(): array
    {
        try {
            $fromFile = collect(DemoParksSeeder::parks())->firstWhere('key', self::PARK_KEY);
        } catch (\Throwable) {
            $fromFile = null;
        }

        return $fromFile ?: self::builtInPark();
    }

    public function handle(ActivityLogger $logger): int
    {
        $created = [];
        $token = null;
        $def = self::parkDefinition();

        DB::transaction(function () use ($logger, $def, &$created, &$token) {
            $company = Company::firstOrCreate(['name' => self::COMPANY_NAME]);
            if ($company->wasRecentlyCreated) {
                $created[] = 'company';
                $logger->log('company.created', $company, ['after' => ['name' => $company->name]], companyId: $company->id);
            }

            $password = (string) env('DEMO_PASSWORD', 'password');
            foreach ([
                [self::ADMIN_EMAIL, 'Safwa Admin', UserRole::Admin, null],
                [self::WORKER_EMAIL, 'Saeed', UserRole::Worker, '0550000003'],
            ] as [$email, $name, $role, $phone]) {
                $user = User::firstOrCreate(['email' => $email], [
                    'company_id' => $company->id, 'role' => $role, 'name' => $name, 'phone' => $phone,
                    'locale' => 'ar', 'password' => $password, // hashed by the model cast
                ]);
                if ($user->wasRecentlyCreated) {
                    $created[] = $email;
                    $logger->log('user.created', $user, ['after' => ['email' => $email, 'role' => $role->value]], companyId: $company->id);
                }
            }

            $park = Park::where('company_id', $company->id)->where('name_en', $def['name_en'])->first();
            if (! $park) {
                $area = (int) round(ParkOutline::area($def['polygon']['coordinates'][0]));
                $park = Park::create(DemoParksSeeder::dataSources($def) + [
                    'company_id' => $company->id,
                    'name_ar' => $def['name_ar'],
                    'name_en' => $def['name_en'],
                    'district' => $def['district'] ?? null,
                    'park_type' => ParkType::from($def['park_type'] ?? 'public_park'),
                    'area_m2' => $area,
                    'polygon' => $def['polygon'],
                    'lat' => $def['lat'],
                    'lng' => $def['lng'],
                    'moisture_min' => 25,
                    'moisture_max' => 70,
                    'auto_irrigation' => true,
                    'baseline_l_per_day' => $area * DemoParksSeeder::BASELINE_L_PER_M2_PER_DAY,
                    'status' => ParkStatus::Ok,
                    'source' => DataSource::Simulated,
                ]);
                $created[] = 'park '.$park->name_en;
                $logger->log('park.created', $park, ['after' => [
                    'name_en' => $park->name_en, 'area_m2' => $area, 'source' => 'simulated', 'outline' => $park->outline_reference,
                ]], companyId: $company->id);
                Realtime::send(new ParkStatusChanged($company->id, ['park_id' => $park->id, 'status' => $park->status->value]));
            }

            if (! Node::where('park_id', $park->id)->where('name', self::NODE_NAME)->exists()) {
                $node = Node::create([
                    'park_id' => $park->id, 'type' => NodeType::Sensor, 'name' => self::NODE_NAME,
                    'has_pump' => true, 'pump_target' => false, 'expected_flow_l_min' => 2.0, 'source' => DataSource::Simulated,
                ]);
                $logger->log('node.created', $node, ['after' => ['name' => self::NODE_NAME, 'type' => 'sensor', 'has_pump' => true, 'source' => 'simulated']],
                    companyId: $company->id);
                $token = [$node->id, $node->name, $park->name_en, 'simulated', $node->createToken('device', ['device'])->plainTextToken];
                $created[] = 'node '.self::NODE_NAME;
            }
        });

        // Outside the transaction: the file is written only once the rows exist (never printed).
        if ($token && ! app()->runningUnitTests()) {
            SimCamera::appendTokenLine($token);
        }

        $this->info($created === []
            ? 'Nothing to do: '.self::COMPANY_NAME.', its accounts and '.$def['name_en'].' are already set up.'
            : 'Done: '.implode(', ', $created).'.');
        $this->line(sprintf('Accounts: %s (admin), %s (worker); password from DEMO_PASSWORD. %s (simulated) belongs to %s.%s',
            self::ADMIN_EMAIL, self::WORKER_EMAIL, $def['name_en'], self::COMPANY_NAME,
            $token ? ' Node token appended to storage/app/private/node-tokens.txt.' : ''));

        return self::SUCCESS;
    }
}
