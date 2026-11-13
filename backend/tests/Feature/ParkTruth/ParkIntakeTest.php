<?php

namespace Tests\Feature\ParkTruth;

use App\Enums\GeometrySource;
use App\Enums\MonitoringMode;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\ParkClaim;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shortlist import (MultiPolygon kept whole, matching, claims, name_unconfirmed), rejected ingest, stand board. */
class ParkIntakeTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/namaa-intake-'.uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function as(User $user): static
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['user']);

        return $this;
    }

    /** @return list<list<float>> */
    private function ring(float $lng, float $lat, float $d): array
    {
        return [[$lng - $d, $lat - $d], [$lng + $d, $lat - $d], [$lng + $d, $lat + $d], [$lng - $d, $lat + $d], [$lng - $d, $lat - $d]];
    }

    /** @param list<array<string, mixed>> $features */
    private function file(array $features): string
    {
        $path = $this->dir.'/in.geojson';
        file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => $features]));

        return $path;
    }

    public function test_multipolygon_keeps_every_part_and_every_hole(): void
    {
        $company = Company::factory()->create();
        $parts = [
            [$this->ring(39.80, 21.40, 0.002), $this->ring(39.80, 21.40, 0.0005)],
            [$this->ring(39.81, 21.40, 0.001)],
            [$this->ring(39.82, 21.40, 0.001), $this->ring(39.82, 21.40, 0.0003), $this->ring(39.8205, 21.4005, 0.0001)],
        ];
        $geometry = ['type' => 'MultiPolygon', 'coordinates' => $parts];
        $expected = (int) round(Geo::area($geometry));
        $largestOnly = (int) round(Geo::area(['type' => 'Polygon', 'coordinates' => $parts[0]]));

        $file = $this->file([['type' => 'Feature', 'geometry' => $geometry, 'properties' => [
            'osm_id' => 'relation/1', 'namaa_mode' => 'satellite_only', 'namaa_name_en' => 'Multi Walkway', 'namaa_name_ar' => 'ممشى', 'namaa_status' => 'confirmed',
        ]]]);

        $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $company->id])->assertSuccessful();

        $park = Park::where('osm_id', 'relation/1')->sole();
        $this->assertSame('MultiPolygon', $park->polygon['type']);
        $this->assertCount(3, $park->polygon['coordinates']);
        $this->assertCount(2, $park->polygon['coordinates'][0]);
        $this->assertCount(3, $park->polygon['coordinates'][2]);
        $this->assertSame($expected, $park->area_m2);
        $this->assertGreaterThan($largestOnly, $park->area_m2);
        $this->assertFalse($park->name_unconfirmed);
    }

    public function test_shortlist_matches_by_osm_id_across_companies_and_moves_the_traced_polygon_to_the_green_zone(): void
    {
        $one = Company::factory()->create();
        $two = Company::factory()->create();
        $traced = ['type' => 'Polygon', 'coordinates' => [$this->ring(39.85, 21.43, 0.0004)]];
        $park = Park::factory()->create([
            'company_id' => $two->id, 'name_en' => 'Al Adel Park', 'osm_id' => 'way/5', 'polygon' => $traced,
            'geometry_source' => GeometrySource::Traced, 'monitoring_mode' => MonitoringMode::Simulated, 'source' => 'simulated',
        ]);
        $osm = ['type' => 'Polygon', 'coordinates' => [$this->ring(39.85, 21.43, 0.001)]];
        $file = $this->file([
            ['type' => 'Feature', 'geometry' => $osm, 'properties' => ['osm_id' => 'way/5', 'namaa_mode' => 'simulated', 'namaa_name_en' => 'Al Adel Park', 'namaa_name_ar' => 'العدل']],
            ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [$this->ring(39.9, 21.4, 0.001)]], 'properties' => [
                'osm_id' => 'way/6', 'namaa_mode' => 'satellite_only', 'namaa_name_en' => 'Garden (name to confirm)', 'namaa_name_ar' => 'حديقة', 'namaa_status' => 'name_to_confirm',
            ]],
        ]);
        file_put_contents($this->dir.'/namaa-park-claims.json', json_encode(['way/6' => [['field' => 'status', 'value' => 'lost water', 'source_name' => 'Okaz', 'source_date' => '2025-12-23']]]));

        $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $one->id])->assertSuccessful();
        $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $one->id])->expectsOutputToContain('unchanged: 2')->assertSuccessful();

        $this->assertSame(2, Park::count());
        $park->refresh();
        $this->assertSame($two->id, $park->company_id);
        $this->assertSame(MonitoringMode::Simulated, $park->monitoring_mode);
        $this->assertSame($osm['coordinates'], $park->polygon['coordinates']);
        $this->assertSame($traced['coordinates'], $park->green_zone['coordinates']);
        $this->assertSame(GeometrySource::Osm, $park->geometry_source);

        $new = Park::where('osm_id', 'way/6')->sole();
        $this->assertSame($one->id, $new->company_id);
        $this->assertTrue($new->name_unconfirmed);
        $this->assertSame(1, ParkClaim::where('park_id', $new->id)->count());

        $admin = User::factory()->admin()->create(['company_id' => $one->id]);
        $this->as($admin)->getJson("/api/admin/parks/{$new->id}")->assertOk()->assertJsonPath('data.name_unconfirmed', true);
    }

    public function test_rejected_posts_to_a_detached_node_or_satellite_park_are_recorded(): void
    {
        $park = Park::factory()->create(['monitoring_mode' => MonitoringMode::SatelliteOnly]);
        $node = Node::factory()->create(['park_id' => $park->id]);
        $token = $node->createToken('device', ['device'])->plainTextToken;

        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $node->id, 'moisture' => 2000])->assertSuccessful();

        $node->refresh();
        $this->assertSame('satellite_only', $node->last_rejected_reason);
        $this->assertSame(1, $node->rejected_count);
        $this->assertNotNull($node->last_rejected_at);
        $this->assertSame(0, $node->readings()->count());

        $admin = User::factory()->admin()->create(['company_id' => $park->company_id]);
        $row = collect($this->as($admin)->getJson('/api/admin/nodes?include_test=1')->assertOk()->json('data'))->firstWhere('id', $node->id);
        $this->assertSame('satellite_only', $row['last_rejected_reason']);
        $this->assertSame(1, $row['rejected_count']);
        $this->assertNotNull($row['last_rejected_at']);
    }

    public function test_stand_setup_creates_the_bench_board_once_and_placeholders_leave_the_setup_list(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $stand = Park::factory()->demoStand()->create(['company_id' => $company->id]);
        $park = Park::factory()->create(['company_id' => $company->id]);
        Node::factory()->create(['park_id' => $park->id, 'name' => 'Model A', 'last_seen_at' => null]);
        Node::factory()->create(['park_id' => $park->id, 'name' => 'Real Board', 'last_seen_at' => null]);

        $setup = fn () => collect($this->as($admin)->getJson('/api/admin/overview')->assertOk()->json('data.needs_attention.setup'))
            ->where('type', 'awaiting_installation')->pluck('node.name')->all();
        $this->assertEqualsCanonicalizing(['Model A', 'Real Board'], $setup()); // the name alone hides nothing

        $this->artisan('namaa:stand:setup', ['--move-real-nodes' => true])->assertSuccessful();
        $this->artisan('namaa:stand:setup')->expectsOutputToContain('already exists')->assertSuccessful();

        $this->assertSame(1, Node::where('park_id', $stand->id)->where('name', 'Bench Board')->count());
        $this->assertSame($stand->id, Node::where('name', 'Model A')->value('park_id'));
        $this->assertTrue((bool) Node::where('name', 'Model A')->value('placeholder'));
        $this->assertFalse((bool) Node::where('name', 'Real Board')->value('placeholder'));
        $this->assertSame(['Real Board'], $setup()); // the moved placeholder is flagged and hidden; the real board still asks
    }

    public function test_the_rejection_warning_clears_on_move_detach_and_accepted_reading(): void
    {
        $park = Park::factory()->create(['monitoring_mode' => MonitoringMode::SatelliteOnly]);
        $other = Park::factory()->create(['company_id' => $park->company_id]);
        $mark = ['last_rejected_at' => now(), 'last_rejected_reason' => 'satellite_only', 'rejected_count' => 3];

        $node = Node::factory()->create(['park_id' => $park->id] + $mark);
        $node->update(['park_id' => $other->id]);
        $this->assertNull($node->refresh()->last_rejected_at);
        $this->assertSame(0, $node->rejected_count);

        $node->forceFill($mark)->save();
        $this->assertSame(3, $node->refresh()->rejected_count, 'saving other fields keeps the warning');
        $node->update(['detached_at' => now()]);
        $this->assertNull($node->refresh()->last_rejected_reason);

        $live = Park::factory()->create(['monitoring_mode' => MonitoringMode::RealSensors]);
        $accepted = Node::factory()->create(['park_id' => $live->id] + $mark);
        $token = $accepted->createToken('device', ['device'])->plainTextToken;
        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $accepted->id, 'moisture' => 2000])->assertSuccessful();
        $accepted->refresh();
        $this->assertNull($accepted->last_rejected_at);
        $this->assertSame(0, $accepted->rejected_count);
    }

    public function test_outline_helpers_are_multipolygon_safe(): void
    {
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [[$this->ring(39.8, 21.4, 0.0005)], [$this->ring(39.9, 21.4, 0.002)]]];
        $this->assertSame($this->ring(39.9, 21.4, 0.002), \App\Services\ParkOutline::outerRing($multi), 'the largest part');

        $park = Park::factory()->create(['polygon' => $multi]);
        $admin = User::factory()->admin()->create(['company_id' => $park->company_id]);
        $saved = app(\App\Services\ParkOutline::class)->save($park, $this->ring(39.8, 21.4, 0.001), 'traced', null, $admin);
        $this->assertSame('Polygon', $saved->polygon['type']);

        $park->forceFill(['polygon' => $multi])->save();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['elements' => []])]);
        $this->artisan('namaa:parks:audit', ['--park' => (string) $park->id])->run();
        $this->assertTrue(true, 'the audit did not crash on a MultiPolygon');
    }
}
