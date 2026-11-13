<?php

namespace Tests\Feature\Admin;

use App\Enums\ParkStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ParkApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Park $park;

    private Node $sensor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->park = Park::factory()->create(['company_id' => $this->company->id, 'status' => ParkStatus::Dry]);
        $this->sensor = Node::factory()->withPump()->create([
            'park_id' => $this->park->id, 'last_moisture_pct' => 21.5, 'last_temperature' => 30.0,
            'last_humidity' => 40.0, 'last_flow' => 0.0, 'last_pump_on' => false, 'last_seen_at' => now(),
        ]);
        Node::factory()->camera()->create(['park_id' => $this->park->id]);
        Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->sensor->id]);
        Alert::factory()->resolved()->create(['park_id' => $this->park->id]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('dashboard', ['user'])->plainTextToken);
    }

    public function test_admin_lists_only_their_company_parks_with_latest_values(): void
    {
        Park::factory()->create(); // another company

        $response = $this->as($this->admin)->getJson('/api/admin/parks');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->park->id)
            ->assertJsonPath('data.0.status', 'dry')
            ->assertJsonPath('data.0.source', 'real')
            ->assertJsonPath('data.0.has_pump', true)
            ->assertJsonPath('data.0.open_alerts_count', 1)
            ->assertJsonPath('data.0.latest.node_id', $this->sensor->id)
            ->assertJsonPath('data.0.latest.moisture_pct', 21.5)
            ->assertJsonPath('data.0.polygon.type', 'Polygon')
            ->assertJsonStructure(['data' => [[
                'id', 'name_ar', 'name_en', 'district', 'park_type', 'area_m2', 'lat', 'lng', 'polygon',
                'status', 'status_changed_at', 'last_reading_at', 'source', 'moisture_min', 'moisture_max',
                'has_pump', 'open_alerts_count',
                'latest' => ['moisture_pct', 'flow', 'temperature', 'humidity', 'pump_on', 'at', 'source'],
            ]]]);
        $this->assertArrayNotHasKey('company', $response->json('data.0'));
    }

    public function test_the_list_has_no_n_plus_one(): void
    {
        $count = function (int $extra): int {
            foreach (range(1, $extra) as $i) {
                $park = Park::factory()->create(['company_id' => $this->company->id]);
                Node::factory()->create(['park_id' => $park->id]);
            }
            $this->as($this->admin); // token creation outside the count
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->getJson('/api/admin/parks')->assertOk();

            return count(\DB::getQueryLog());
        };

        $few = $count(3);
        $many = $count(5); // 8 more parks in total than the first run started with

        // The count must not grow with the number of parks.
        $this->assertSame($few, $many);
        // token lookup + token touch + user + parks (with the open-alert count subquery) + nodes + latest pump log
        // + latest NDVI reading + latest composite
        $this->assertLessThanOrEqual(8, $many);
    }

    public function test_park_detail_has_nodes_and_open_alerts(): void
    {
        $this->as($this->admin)->getJson("/api/admin/parks/{$this->park->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.nodes')
            ->assertJsonPath('data.nodes.0.id', $this->sensor->id)
            ->assertJsonPath('data.nodes.0.online', true)
            ->assertJsonPath('data.nodes.1.type', 'camera')
            ->assertJsonCount(1, 'data.open_alerts')
            ->assertJsonPath('data.open_alerts.0.type', 'dry')
            ->assertJsonStructure(['data' => ['nodes' => [['id', 'name', 'type', 'last_seen_at', 'online', 'source', 'has_pump', 'pump_target']]]]);
    }

    public function test_another_company_admin_gets_404(): void
    {
        $other = User::factory()->admin()->create(); // own new company

        $this->as($other)->getJson("/api/admin/parks/{$this->park->id}")->assertNotFound();
        $this->as($other)->getJson("/api/admin/parks/{$this->park->id}/readings")->assertNotFound();
        $this->as($other)->patchJson("/api/admin/parks/{$this->park->id}", ['moisture_min' => 99])->assertNotFound();
        $this->assertSame(25, $this->park->refresh()->moisture_min);
    }

    public function test_worker_and_municipality_get_403_on_admin_routes(): void
    {
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $municipality = User::factory()->municipality()->create();

        $this->as($worker)->getJson('/api/admin/parks')->assertForbidden();
        $this->as($municipality)->getJson('/api/admin/parks')->assertForbidden();
        $this->as($worker)->patchJson("/api/admin/parks/{$this->park->id}", ['moisture_min' => 20])->assertForbidden();
    }

    public function test_readings_are_bucketed(): void
    {
        Carbon::setTestNow('2026-11-08 12:02:00');
        $at = fn (string $t) => Carbon::parse($t);
        foreach ([
            ['2026-11-08 11:50:10', 40.0, 30.0],
            ['2026-11-08 11:52:40', 42.0, 32.0],  // same 5-min bucket as above (11:50)
            ['2026-11-08 11:56:00', 50.0, null],  // bucket 11:55
            ['2026-11-07 10:00:00', 10.0, 20.0],  // older than 24 h: out
        ] as [$time, $moisture, $temperature]) {
            Reading::factory()->create([
                'node_id' => $this->sensor->id, 'park_id' => $this->park->id,
                'moisture_pct' => $moisture, 'temperature' => $temperature, 'created_at' => $at($time),
            ]);
        }

        $day = $this->as($this->admin)->getJson("/api/admin/parks/{$this->park->id}/readings?range=24h");

        // Every 5-min bucket of the window, oldest first: 2026-11-07 12:00 ... 2026-11-08 12:00.
        $day->assertOk()
            ->assertJsonPath('range', '24h')
            ->assertJsonPath('bucket_seconds', 300)
            ->assertJsonPath('source', 'real')
            ->assertJsonCount(289, 'data')
            ->assertJsonPath('data.0.t', '2026-11-07T12:00:00Z')
            ->assertJsonPath('data.0.moisture_pct', null)
            ->assertJsonPath('data.0.readings', 0)
            ->assertJsonPath('data.286.t', '2026-11-08T11:50:00Z')
            ->assertJsonPath('data.286.moisture_pct', 41)
            ->assertJsonPath('data.286.temperature', 31)
            ->assertJsonPath('data.286.readings', 2)
            ->assertJsonPath('data.287.t', '2026-11-08T11:55:00Z')
            ->assertJsonPath('data.287.temperature', null)
            ->assertJsonPath('data.288.t', '2026-11-08T12:00:00Z')
            ->assertJsonPath('data.288.readings', 0);
        $this->assertSame(3, collect($day->json('data'))->sum('readings'), 'a gap is null, never interpolated');

        $week = $this->getJson("/api/admin/parks/{$this->park->id}/readings?range=7d");
        $week->assertOk()->assertJsonPath('bucket_seconds', 3600)->assertJsonCount(169, 'data');
        $filled = collect($week->json('data'))->where('readings', '>', 0)->values();
        $this->assertSame(['2026-11-07T10:00:00Z', '2026-11-08T11:00:00Z'], $filled->pluck('t')->all());
        $this->assertSame(3, $filled[1]['readings']);

        // 1 hour, 30 s buckets: 11:02:00 ... 12:02:00.
        $hour = $this->getJson("/api/admin/parks/{$this->park->id}/readings?range=1h");
        $hour->assertOk()->assertJsonPath('bucket_seconds', 30)->assertJsonCount(121, 'data')
            ->assertJsonPath('data.0.t', '2026-11-08T11:02:00Z');
        $this->assertSame(['2026-11-08T11:50:00Z', '2026-11-08T11:52:30Z', '2026-11-08T11:56:00Z'],
            collect($hour->json('data'))->where('readings', '>', 0)->pluck('t')->values()->all());

        $this->getJson("/api/admin/parks/{$this->park->id}/readings?range=1y")->assertStatus(422);
        Carbon::setTestNow();
    }

    public function test_patch_updates_thresholds_and_logs_the_diff(): void
    {
        $this->as($this->admin)->patchJson("/api/admin/parks/{$this->park->id}", ['moisture_min' => 30, 'moisture_max' => 65])
            ->assertOk()
            ->assertJsonPath('data.moisture_min', 30)
            ->assertJsonPath('data.moisture_max', 65);

        $log = ActivityLog::where('action', 'park.updated')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($this->company->id, $log->company_id);
        $this->assertSame(['moisture_min' => 25, 'moisture_max' => 70], $log->diff['before']);
        $this->assertSame(['moisture_min' => 30, 'moisture_max' => 65], $log->diff['after']);
    }

    public function test_patch_validation(): void
    {
        $url = "/api/admin/parks/{$this->park->id}";

        $this->as($this->admin)->patchJson($url, ['moisture_min' => 80, 'moisture_max' => 60])->assertStatus(422)->assertJsonValidationErrors('moisture_min');
        $this->patchJson($url, ['moisture_min' => 75])->assertStatus(422); // 75 >= current max 70
        $this->patchJson($url, ['moisture_max' => 101])->assertStatus(422)->assertJsonValidationErrors('moisture_max');
        $this->patchJson($url, ['moisture_min' => 'a'])->assertStatus(422);
        $this->patchJson($url, ['moisture_max' => 60])->assertOk()->assertJsonPath('data.moisture_max', 60);
    }

    public function test_municipality_sees_every_company_read_only(): void
    {
        $otherPark = Park::factory()->create();
        $municipality = User::factory()->municipality()->create();

        $this->as($municipality)->getJson('/api/municipality/parks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.company.id', $this->park->company_id)
            ->assertJsonPath('data.1.company.id', $otherPark->company_id);

        $this->as($municipality)->patchJson("/api/admin/parks/{$this->park->id}", ['moisture_min' => 20])->assertForbidden();
        $this->as($this->admin)->getJson('/api/municipality/parks')->assertForbidden();
    }
}
