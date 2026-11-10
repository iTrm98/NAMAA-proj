<?php

namespace Tests\Feature\Outlines;

use App\Models\Park;
use App\Services\ParkOutlines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sprint 3, E: park outlines from OpenStreetMap (Overpass faked; nothing leaves the test). */
class ParkOutlinesTest extends TestCase
{
    use RefreshDatabase;

    private Park $park;

    private string $seedPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.overpass_url' => 'http://overpass.test/api/interpreter']);
        $this->park = Park::factory()->create(['name_en' => 'Test Park', 'lat' => 21.4, 'lng' => 39.8, 'area_m2' => 5000]);

        $this->seedPath = storage_path('framework/testing/parks-'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($this->seedPath));
        // Same shape as data/seed/parks.json: pretty-printed, nodes after polygon_approximate.
        File::put($this->seedPath, json_encode(['notes' => ['x'], 'parks' => [
            ['key' => 'test', 'name_en' => 'Test Park', 'lat' => 21.4, 'lng' => 39.8, 'area_m2' => 5000, 'polygon_approximate' => true, 'nodes' => []],
            ['key' => 'other', 'name_en' => 'Other Park', 'lat' => 21.5, 'lng' => 39.9, 'area_m2' => 7000, 'polygon_approximate' => true, 'nodes' => []],
        ]], JSON_PRETTY_PRINT)."\n");
        config(['namaa.parks_seed_path' => $this->seedPath]);
    }

    protected function tearDown(): void
    {
        File::delete($this->seedPath);
        parent::tearDown();
    }

    /** A square way of about 2 x 2 half-sizes (degrees) around a centre. */
    private static function way(int $id, float $lat, float $lng, float $half, ?string $name = null): array
    {
        $ring = [[$lat - $half, $lng - $half], [$lat - $half, $lng + $half], [$lat + $half, $lng + $half], [$lat + $half, $lng - $half], [$lat - $half, $lng - $half]];

        return ['type' => 'way', 'id' => $id, 'tags' => array_filter(['leisure' => 'park', 'name' => $name]),
            'geometry' => array_map(fn ($p) => ['lat' => $p[0], 'lon' => $p[1]], $ring)];
    }

    private function fakeOverpass(array $elements): void
    {
        Http::fake(['overpass.test/*' => Http::response(['elements' => $elements])]);
    }

    public function test_candidates_are_parsed_measured_and_the_smallest_containing_one_is_picked(): void
    {
        $this->fakeOverpass([
            self::way(1, 21.4, 39.8, 0.002, 'Big Park'),        // about 445 m x 414 m, contains the marker
            self::way(2, 21.4, 39.8, 0.0003, 'Inner Garden'),   // small, contains the marker
            self::way(3, 21.402, 39.802, 0.0005, 'Next Door'),  // does not contain it
        ]);

        $candidates = app(ParkOutlines::class)->candidates(21.4, 39.8);
        $pick = ParkOutlines::pick($candidates);

        $this->assertCount(3, $candidates);
        $this->assertTrue($candidates[0]['contains']);
        $this->assertFalse($candidates[2]['contains']);
        $this->assertSame('way/2', $pick['osm']);
        $this->assertEqualsWithDelta(0.0006 * 111_195 * 0.0006 * 111_195 * cos(deg2rad(21.4)), $pick['area_m2'], 50);
        $big = collect($candidates)->firstWhere('osm', 'way/1');
        $this->assertEqualsWithDelta(184_200, $big['area_m2'], 500); // 444.8 m x 414.1 m

        Http::assertSent(function ($request) {
            $agent = $request->header('User-Agent')[0] ?? '';

            return str_contains($agent, 'NAMAA') && ! str_contains($agent, '@')
                && str_contains($request['data'], 'around:300,21.4')
                && str_contains($request['data'], 'leisure');
        });
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->fakeOverpass([self::way(7, 21.4, 39.8, 0.001, 'Test Park')]);
        $before = File::get($this->seedPath);

        $this->artisan('namaa:parks:outlines')->expectsOutputToContain('way/7')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(5000, $this->park->fresh()->area_m2);
        $this->assertSame($before, File::get($this->seedPath));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'park.outline_updated']);
    }

    public function test_write_updates_polygon_and_area_only_in_db_and_seed_and_logs_it(): void
    {
        $this->fakeOverpass([self::way(7, 21.4, 39.8, 0.001, 'Test Park')]);
        $before = $this->park->only(['id', 'name_en', 'name_ar', 'lat', 'lng', 'company_id']);

        $this->artisan('namaa:parks:outlines', ['--write' => true])
            ->expectsOutputToContain('namaa:ndvi --days=90 --sync --park='.$this->park->id)
            ->assertSuccessful();

        $park = $this->park->fresh();
        $this->assertSame($before, $park->only(array_keys($before)), 'id, names and coordinates never change');
        $this->assertSame(5, count($park->polygon['coordinates'][0]));
        $this->assertEqualsWithDelta(46_050, $park->area_m2, 200); // 222.4 m x 207.1 m
        $this->assertDatabaseHas('activity_logs', ['action' => 'park.outline_updated', 'subject_id' => $park->id]);

        $text = File::get($this->seedPath);
        $seed = json_decode($text, true);
        $this->assertNotNull($seed, 'still valid JSON');
        $this->assertSame(1, substr_count($text, '"polygon":'), 'one one-line polygon added');

        // A second run finds the same outline: nothing changes, nothing is logged.
        $this->artisan('namaa:parks:outlines', ['--write' => true])->expectsOutputToContain('Already saved')->assertSuccessful();
        $this->assertSame($text, File::get($this->seedPath));
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'park.outline_updated')->count());

        $this->assertSame('test', $seed['parks'][0]['key']);
        $this->assertSame(21.4, $seed['parks'][0]['lat']);
        $this->assertFalse($seed['parks'][0]['polygon_approximate']);
        $this->assertSame($park->area_m2, $seed['parks'][0]['area_m2']);
        $this->assertStringContainsString('way/7', $seed['parks'][0]['polygon_source']);
        $this->assertSame(7000, $seed['parks'][1]['area_m2'], 'other parks untouched');
        $this->assertArrayNotHasKey('polygon', $seed['parks'][1]);
    }

    public function test_nothing_is_written_when_no_outline_contains_the_marker(): void
    {
        $this->fakeOverpass([self::way(9, 21.403, 39.803, 0.0005, 'Elsewhere')]);

        $this->artisan('namaa:parks:outlines', ['--write' => true])->expectsOutputToContain('nothing picked')->assertSuccessful();

        $this->assertSame(5000, $this->park->fresh()->area_m2);
    }

    public function test_an_overpass_failure_is_reported_and_fails_the_command(): void
    {
        Http::fake(['overpass.test/*' => Http::response('busy', 500)]);

        $this->artisan('namaa:parks:outlines')->expectsOutputToContain('HTTP 500')->assertFailed();
    }
}
