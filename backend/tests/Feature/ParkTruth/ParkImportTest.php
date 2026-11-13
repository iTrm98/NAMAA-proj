<?php

namespace Tests\Feature\ParkTruth;

use App\Enums\GeometrySource;
use App\Enums\MonitoringMode;
use App\Models\Company;
use App\Models\Park;
use App\Models\ParkClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 6.2, Phase A: fetch-osm, import, park-setup and claims endpoints. */
class ParkImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/namaa-parks-'.uniqid();
        mkdir($this->dir, 0775, true);
        config(['namaa.parks_data_path' => $this->dir]);
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,osm/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        @rmdir($this->dir.'/osm');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['user']);

        return $this;
    }

    /** @return array<string, mixed> */
    private function polygon(float $lng = 39.82, float $lat = 21.42, float $d = 0.001): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lng - $d, $lat - $d], [$lng + $d, $lat - $d], [$lng + $d, $lat + $d], [$lng - $d, $lat + $d], [$lng - $d, $lat - $d]]]];
    }

    /** @param array<string, mixed> $props @return array<string, mixed> */
    private function feature(array $props, ?array $geometry = null): array
    {
        return ['type' => 'Feature', 'properties' => $props, 'geometry' => $geometry ?? $this->polygon()];
    }

    /** @param list<array<string, mixed>> $features */
    private function file(array $features): string
    {
        $path = $this->dir.'/in-'.uniqid().'.geojson';
        file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => $features]));

        return $path;
    }

    private function import(string $file, string ...$flags): int
    {
        return $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $this->company->id, ...array_fill_keys($flags, true)])->run();
    }

    public function test_import_twice_creates_no_duplicates_and_second_run_is_unchanged(): void
    {
        $file = $this->file([
            $this->feature(['osm_id' => 'way/1', 'name' => 'Alpha Park', 'name:ar' => 'ألفا', 'monitoring_mode' => 'simulated',
                'claims' => [['field' => 'opened_year', 'value' => '1999', 'source_name' => 'Municipality']]]),
            $this->feature(['osm_id' => 'way/2', 'name:en' => 'Beta Garden'], $this->polygon(39.83)),
        ]);

        $this->import($file);
        $this->assertSame(2, Park::count());
        $alpha = Park::where('osm_id', 'way/1')->sole();
        $this->assertSame(MonitoringMode::SatelliteOnly, $alpha->monitoring_mode);
        $this->assertSame('real', $alpha->source->value);
        $this->assertSame('osm-way-1', $alpha->slug);
        $this->assertSame(1, ParkClaim::count());

        $this->import($file);
        $this->assertSame(2, Park::count());
        $this->assertSame(1, ParkClaim::count());

        $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('unchanged: 2')->assertSuccessful();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $file = $this->file([$this->feature(['osm_id' => 'way/1', 'name' => 'Alpha Park', 'claims' => [['field' => 'status', 'value' => 'open', 'source_name' => 'X']]])]);

        $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $this->company->id, '--dry-run' => true])
            ->expectsOutputToContain('create: 1')->assertSuccessful();

        $this->assertSame(0, Park::count());
        $this->assertSame(0, ParkClaim::count());
    }

    public function test_existing_park_mode_is_untouched_and_matched_by_name(): void
    {
        $park = Park::factory()->create([
            'company_id' => $this->company->id, 'name_en' => 'Al Adel Park', 'monitoring_mode' => MonitoringMode::Simulated, 'source' => 'simulated',
        ]);
        $file = $this->file([$this->feature(['osm_id' => 'way/9', 'name' => 'Al Adel Park', 'monitoring_mode' => 'satellite_only'])]);

        $this->import($file);

        $this->assertSame(1, Park::count());
        $park->refresh();
        $this->assertSame(MonitoringMode::Simulated, $park->monitoring_mode);
        $this->assertSame('simulated', $park->source->value);
        $this->assertSame('way/9', $park->osm_id);
    }

    public function test_ambiguous_name_is_reported_not_guessed(): void
    {
        Park::factory()->count(2)->create(['company_id' => $this->company->id, 'name_en' => 'Twin Park']);
        $file = $this->file([$this->feature(['name' => 'Twin Park'])]);

        $this->artisan('namaa:parks:import', ['file' => $file, '--company' => $this->company->id])
            ->expectsOutputToContain('ambiguous: 1')->assertSuccessful();
        $this->assertSame(2, Park::count());
    }

    public function test_traced_geometry_is_protected_unless_forced(): void
    {
        $traced = $this->polygon(39.9, 21.5, 0.0005);
        $park = Park::factory()->create([
            'company_id' => $this->company->id, 'slug' => 'traced-x', 'name_en' => 'Traced X',
            'polygon' => $traced, 'geometry_source' => GeometrySource::Traced,
        ]);
        $file = $this->file([$this->feature(['slug' => 'traced-x', 'name' => 'Traced X'], $this->polygon())]);

        $this->import($file);
        $this->assertSame($traced, $park->refresh()->polygon);

        $this->import($file, '--force-geometry');
        $park->refresh();
        $this->assertNotSame($traced, $park->polygon);
        $this->assertSame(GeometrySource::Osm, $park->geometry_source);
    }

    public function test_fetch_osm_writes_the_file_and_finds_juranah(): void
    {
        Http::fake(['*' => Http::response(['elements' => [
            ['type' => 'way', 'id' => 457991700, 'tags' => ['leisure' => 'park', 'name' => 'حديقة الجعرانة', 'name:en' => "Ju'ranah Park"],
                'geometry' => array_map(fn ($p) => ['lon' => $p[0], 'lat' => $p[1]], $this->polygon()['coordinates'][0])],
            ['type' => 'way', 'id' => 5, 'tags' => ['leisure' => 'garden', 'name' => 'Other'], 'geometry' => [['lat' => 1, 'lon' => 1]]],
        ]])]);

        $this->artisan('namaa:parks:fetch-osm')
            ->expectsOutputToContain('Features: 1')
            ->expectsOutputToContain("Ju'ranah match: way/457991700")
            ->assertSuccessful();

        $file = $this->dir.'/osm/makkah-osm-'.now()->format('Ymd').'.geojson';
        $this->assertFileExists($file);
        $data = json_decode(file_get_contents($file), true);
        $this->assertSame('way/457991700', $data['features'][0]['properties']['osm_id']);
        $this->assertGreaterThan(10000, $data['features'][0]['properties']['area_m2']);
        $this->assertSame(0, Park::count());

        $this->as($this->admin)->getJson('/api/admin/park-setup/osm')
            ->assertOk()->assertJsonPath('features', 1)->assertJsonPath('ge_1ha', 1);
    }

    public function test_osm_endpoint_404_before_first_fetch(): void
    {
        $this->as($this->admin)->getJson('/api/admin/park-setup/osm')->assertNotFound()->assertJsonStructure(['hint']);
    }

    public function test_selection_roundtrip_and_cap(): void
    {
        $f = fn (string $id, string $mode) => $this->feature(['osm_id' => $id, 'name' => $id, 'monitoring_mode' => $mode]);

        $this->as($this->admin)->putJson('/api/admin/park-setup/selection', ['features' => [$f('way/1', 'simulated'), $f('way/2', 'satellite_only')]])
            ->assertOk()->assertJsonPath('simulated', 1)->assertJsonPath('cap', 4);
        $this->as($this->admin)->getJson('/api/admin/park-setup/selection')->assertOk()->assertJsonCount(2, 'features');

        // One existing simulated park + four marked in the file = 5 > 4.
        Park::factory()->create(['company_id' => $this->company->id, 'monitoring_mode' => MonitoringMode::Simulated, 'source' => 'simulated']);
        $five = array_map(fn ($i) => $f("way/1$i", 'simulated'), range(1, 4));
        $this->as($this->admin)->putJson('/api/admin/park-setup/selection', ['features' => $five])
            ->assertStatus(422)->assertJsonValidationErrors('features');
        $this->as($this->admin)->putJson('/api/admin/park-setup/selection', ['features' => array_slice($five, 0, 3)])->assertOk();
    }

    public function test_setup_endpoints_are_admin_only_and_validate(): void
    {
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->as($worker)->getJson('/api/admin/park-setup/selection')->assertForbidden();
        $this->as($worker)->putJson('/api/admin/park-setup/selection', ['features' => []])->assertForbidden();
        $this->as($this->admin)->putJson('/api/admin/park-setup/selection', [])->assertStatus(422);
    }

    public function test_claims_are_company_scoped(): void
    {
        $park = Park::factory()->create(['company_id' => $this->company->id]);
        $other = Park::factory()->create(['company_id' => Company::factory()->create()->id]);
        $body = ['field' => 'opened_year', 'value' => '2001', 'source_name' => 'Municipality', 'source_url' => 'https://example.com/x'];

        $id = $this->as($this->admin)->postJson("/api/admin/parks/{$park->id}/claims", $body)->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/admin/parks/{$park->id}/claims", $body)->assertStatus(422);
        $this->as($this->admin)->postJson("/api/admin/parks/{$park->id}/claims", ['field' => 'nope'])->assertStatus(422);
        $this->as($this->admin)->getJson("/api/admin/parks/{$park->id}/claims")->assertOk()->assertJsonCount(1, 'data');

        $this->as($this->admin)->getJson("/api/admin/parks/{$other->id}/claims")->assertNotFound();
        $this->as($this->admin)->postJson("/api/admin/parks/{$other->id}/claims", $body)->assertNotFound();
        $foreign = ParkClaim::factory()->create(['park_id' => $other->id]);
        $this->as($this->admin)->deleteJson("/api/admin/claims/{$foreign->id}")->assertNotFound();

        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->as($worker)->postJson("/api/admin/parks/{$park->id}/claims", $body)->assertForbidden();

        $this->as($this->admin)->deleteJson("/api/admin/claims/{$id}")->assertNoContent();
        $this->assertSame(0, $park->claims()->count());
    }
}
