<?php

namespace Tests\Feature\ParkTruth;

use App\Enums\MonitoringMode;
use App\Enums\ParkStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\ParkClaim;
use App\Models\User;
use App\Services\ParkOutline;
use App\Services\ParkStatusService;
use App\Support\Geo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sprint 6.2, Phase A: park truth (geometry, backfill, scoping, no_sensor). */
class ParkTruthTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: float, 1: float}> a closed square ring of `$d` degrees around a point */
    private function square(float $d, float $lng = 39.82, float $lat = 21.42): array
    {
        return [[$lng - $d, $lat - $d], [$lng + $d, $lat - $d], [$lng + $d, $lat + $d], [$lng - $d, $lat + $d], [$lng - $d, $lat - $d]];
    }

    public function test_polygon_area_matches_the_ring_area_used_before(): void
    {
        $ring = $this->square(0.001);
        $polygon = ['type' => 'Polygon', 'coordinates' => [$ring]];

        $this->assertEqualsWithDelta(ParkOutline::area($ring), Geo::area($polygon), 0.001);
        $this->assertEqualsWithDelta(Geo::ringArea($ring), ParkOutline::area($ring), 0.001);
        // 0.002 deg x 0.002 deg at 21.42 N is about 207 m x 221 m.
        $this->assertEqualsWithDelta(207.4 * 221.1, Geo::area($polygon), 500);
    }

    public function test_a_hole_is_subtracted(): void
    {
        $outer = $this->square(0.002);
        $hole = $this->square(0.0005);
        $full = Geo::area(['type' => 'Polygon', 'coordinates' => [$outer]]);
        $holed = Geo::area(['type' => 'Polygon', 'coordinates' => [$outer, $hole]]);

        $this->assertEqualsWithDelta($full - Geo::ringArea($hole), $holed, 0.001);
        $this->assertLessThan($full, $holed);
    }

    public function test_a_multipolygon_adds_its_parts(): void
    {
        $a = $this->square(0.001);
        $b = $this->square(0.0005, 39.83);
        $inner = $this->square(0.0001, 39.83);
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [[$a], [$b, $inner]]];

        $this->assertEqualsWithDelta(Geo::ringArea($a) + Geo::ringArea($b) - Geo::ringArea($inner), Geo::area($multi), 0.001);
        $this->assertSame(0.0, Geo::area(null));
        $this->assertSame(0.0, Geo::area(['type' => 'Point', 'coordinates' => [1, 2]]));
    }

    public function test_centroid_bbox_and_point_in_polygon(): void
    {
        $poly = ['type' => 'Polygon', 'coordinates' => [$this->square(0.002), $this->square(0.0005)]];

        [$lng, $lat] = Geo::centroid($poly);
        $this->assertEqualsWithDelta(39.82, $lng, 1e-6);
        $this->assertEqualsWithDelta(21.42, $lat, 1e-6);
        $this->assertEqualsWithDelta([39.818, 21.418, 39.822, 21.422], Geo::bbox($poly), 1e-9);
        $this->assertNull(Geo::bbox(null));

        $this->assertTrue(Geo::pointInPolygon(39.8215, 21.4215, $poly));
        $this->assertFalse(Geo::pointInPolygon(39.82, 21.42, $poly)); // inside the hole
        $this->assertFalse(Geo::pointInPolygon(39.9, 21.5, $poly));
    }

    public function test_the_backfill_maps_the_old_columns(): void
    {
        $company = Company::factory()->create();
        $make = fn (array $o) => Park::factory()->create(['company_id' => $company->id] + $o)->id;

        $osm = $make(['outline_source' => 'osm', 'outline_reference' => 'OpenStreetMap way/457991700 (ODbL)', 'outline_at' => '2026-11-10 08:00:00']);
        $rel = $make(['outline_source' => 'official', 'outline_reference' => 'OSM relation/123', 'source' => 'simulated']);
        $rough = $make(['outline_source' => 'approximate', 'outline_reference' => 'Rectangle of the estimated area']);
        $bench = $make(['outline_source' => 'traced', 'is_test' => true]);

        DB::table('parks')->update([
            'site_type' => 'park', 'monitoring_mode' => 'satellite_only', 'geometry_source' => 'rough_legacy',
            'osm_id' => null, 'slug' => null, 'geometry_updated_at' => null,
        ]);

        (require database_path('migrations/2026_11_18_000001_add_park_truth_to_parks_table.php'))->backfill();

        $row = fn (int $id) => DB::table('parks')->find($id);
        $this->assertSame('osm', $row($osm)->geometry_source);
        $this->assertSame('way/457991700', $row($osm)->osm_id);
        $this->assertSame('osm-way-457991700', $row($osm)->slug);
        $this->assertSame('2026-11-10 08:00:00', $row($osm)->geometry_updated_at);
        $this->assertSame('real_sensors', $row($osm)->monitoring_mode);
        $this->assertSame('unverified', $row($osm)->verification_status);
        $this->assertSame('park', $row($osm)->site_type);

        $this->assertSame('traced', $row($rel)->geometry_source);
        $this->assertSame('relation/123', $row($rel)->osm_id);
        $this->assertSame('simulated', $row($rel)->monitoring_mode);

        $this->assertSame('rough_legacy', $row($rough)->geometry_source);
        $this->assertNull($row($rough)->osm_id);
        $this->assertNull($row($rough)->slug);

        $this->assertSame('demo_stand', $row($bench)->site_type);
        $this->assertSame('traced', $row($bench)->geometry_source);
    }

    public function test_the_stand_and_archived_parks_are_not_parks(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $park = Park::factory()->create(['company_id' => $company->id]);
        $stand = Park::factory()->demoStand()->create(['company_id' => $company->id]);
        $old = Park::factory()->archived()->create(['company_id' => $company->id]);

        $this->assertSame([$park->id], Park::parks()->pluck('id')->all());

        $ids = fn (string $q) => collect($this->asUser($admin)->getJson('/api/admin/parks'.$q)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$park->id], $ids(''));
        $this->assertEqualsCanonicalizing([$park->id, $stand->id], $ids('?include_test=1'));
        $this->assertEqualsCanonicalizing([$park->id, $stand->id, $old->id], $ids('?include_test=1&include_archived=1'));

        // Archived parks stay reachable by id.
        $this->asUser($admin)->getJson("/api/admin/parks/{$old->id}")->assertOk();

        $overview = $this->asUser($admin)->getJson('/api/admin/overview')->assertOk()->json('data');
        $this->assertSame(1, $overview['kpis']['parks']['total']);

        $muni = User::factory()->municipality()->create();
        $this->assertSame([$park->id], collect($this->asUser($muni)->getJson('/api/municipality/parks')->assertOk()->json('data'))->pluck('id')->all());
    }

    public function test_the_park_resource_carries_the_new_fields_and_claims(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $park = Park::factory()->create(['company_id' => $company->id, 'area_m2' => 5000]);
        ParkClaim::factory()->create(['park_id' => $park->id, 'field' => 'opened_year', 'value' => '1999', 'source_name' => 'Municipality']);

        $list = $this->asUser($admin)->getJson('/api/admin/parks')->assertOk()->json('data.0');
        $this->assertSame('park', $list['site_type']);
        $this->assertSame('real_sensors', $list['monitoring_mode']);
        $this->assertSame('unverified', $list['verification_status']);
        $this->assertSame('rough_legacy', $list['geometry_source']);
        $this->assertSame(5000, $list['boundary_area_m2']);
        $this->assertSame($park->polygon, $list['boundary']);
        $this->assertNull($list['green_zone']);
        $this->assertNull($list['cover_photo']);
        $this->assertSame('real', $list['source']);
        $this->assertArrayNotHasKey('claims', $list);

        $detail = $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}")->assertOk()->json('data');
        $this->assertSame('opened_year', $detail['claims'][0]['field']);
        $this->assertSame('Municipality', $detail['claims'][0]['source_name']);
    }

    public function test_a_satellite_only_park_is_no_sensor_and_detached_nodes_are_skipped(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $park = Park::factory()->satelliteOnly()->create(['company_id' => $company->id]);
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()]);
        $status = app(ParkStatusService::class);

        $this->assertSame(ParkStatus::NoSensor, $status->compute($park));
        $this->assertTrue($status->recalculate($park));
        $this->assertSame(ParkStatus::NoSensor, $park->refresh()->status);

        // Still no_sensor with an open alert; never ok.
        Alert::factory()->create(['park_id' => $park->id, 'type' => 'dry']);
        $this->assertSame(ParkStatus::NoSensor, $status->compute($park));

        // A real-sensor park whose only node is detached is offline.
        $real = Park::factory()->create(['company_id' => $company->id, 'monitoring_mode' => MonitoringMode::RealSensors]);
        Node::factory()->create(['park_id' => $real->id, 'last_seen_at' => now(), 'detached_at' => now()]);
        $this->assertSame(ParkStatus::Offline, $status->compute($real));

        // Detached never-reported nodes are not "awaiting installation" on the overview.
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null, 'source' => 'real', 'detached_at' => now()]);
        $data = $this->asUser($admin)->getJson('/api/admin/overview')->assertOk()->json('data');
        $this->assertSame(0, $data['right_now']['sensors_awaiting_installation']);
    }

    public function test_demo_role_uniqueness_is_not_enforced_by_the_database(): void
    {
        $company = Company::factory()->create();
        Park::factory()->count(2)->create(['company_id' => $company->id, 'demo_role' => 'dry']);

        $this->assertSame(2, Park::where('demo_role', 'dry')->count());
    }

    public function test_claims_are_unique_per_field_value_and_source(): void
    {
        $park = Park::factory()->create();
        ParkClaim::factory()->create(['park_id' => $park->id, 'field' => 'total_area_m2', 'value' => '1000', 'source_name' => 'A']);
        ParkClaim::factory()->create(['park_id' => $park->id, 'field' => 'total_area_m2', 'value' => '2000', 'source_name' => 'A']);

        $this->expectException(QueryException::class);
        ParkClaim::factory()->create(['park_id' => $park->id, 'field' => 'total_area_m2', 'value' => '1000', 'source_name' => 'A']);
    }
}
