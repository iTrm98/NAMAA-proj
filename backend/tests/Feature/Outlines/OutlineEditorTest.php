<?php

namespace Tests\Feature\Outlines;

use App\Enums\AlertType;
use App\Jobs\RecomputeNdvi;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Company;
use App\Models\NdviReading;
use App\Models\Park;
use App\Models\User;
use App\Services\NdviService;
use App\Services\ParkOutline;
use Database\Seeders\DemoParksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sprint 3.1, D1-D3: data sources, the outline editor endpoint, the NDVI recompute. */
class OutlineEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Park $park;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $company->id, 'name' => 'Turki']);
        $this->park = Park::factory()->create(['company_id' => $company->id, 'lat' => 21.4150694, 'lng' => 39.8664169, 'area_m2' => 6000]);
    }

    /** A ring of $areaM2 around a point, not closed (the editor may send it either way). @return list<list<float>> */
    private function square(float $lat, float $lng, int $areaM2): array
    {
        $ring = DemoParksSeeder::rectangle($lat, $lng, $areaM2)['coordinates'][0];

        return array_slice($ring, 0, 4);
    }

    private function save(array $ring, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->asUser($this->admin)->putJson("/api/admin/parks/{$this->park->id}/outline", $extra + [
            'polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]],
        ]);
    }

    public function test_geodesic_area_matches_a_known_rectangle(): void
    {
        // 0.001° x 0.001° at the equator: R² × Δλ × Δ(sin φ) on the sphere of R = 6378137 m, as @turf/area.
        $ring = [[0, 0], [0.001, 0], [0.001, 0.001], [0, 0.001]];
        $this->assertEqualsWithDelta(12392.0, ParkOutline::area($ring), 5.0);
        // The seeder's local-projection rectangle agrees within 1 %.
        $this->assertEqualsWithDelta(6000, ParkOutline::area($this->square(21.4150694, 39.8664169, 6000)), 60);
    }

    public function test_saving_stores_the_outline_area_and_source_logs_it_and_queues_the_ndvi_recompute(): void
    {
        Queue::fake();
        $ring = $this->square($this->park->lat, $this->park->lng + 0.0005, 1500);

        $this->save($ring)->assertOk()
            ->assertJsonPath('data.area_m2', fn ($a) => abs($a - 1500) < 20)
            ->assertJsonPath('data.sources.outline.type', 'traced')
            ->assertJsonPath('data.sources.outline.by.name', 'Turki')
            ->assertJsonPath('data.sources.area.type', 'traced')
            ->assertJsonPath('data.ndvi_recomputing', true);

        $park = $this->park->refresh();
        $this->assertCount(5, $park->polygon['coordinates'][0], 'stored closed');
        $this->assertSame('traced', $park->outline_source);
        $this->assertSame($this->admin->id, $park->outline_by);
        $log = ActivityLog::where('action', 'park.outline_updated')->sole();
        $this->assertSame(6000, $log->diff['before']['area_m2']);
        $this->assertEqualsWithDelta(1500, $log->diff['after']['area_m2'], 20);
        Queue::assertPushed(RecomputeNdvi::class, fn ($job) => $job->parkId === $this->park->id);
    }

    public function test_official_needs_a_reference(): void
    {
        Queue::fake();
        $ring = $this->square($this->park->lat, $this->park->lng, 1500);

        $this->save($ring, ['source' => 'official'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->save($ring, ['source' => 'official', 'reference' => 'Amanah plan 14/2026'])->assertOk()
            ->assertJsonPath('data.sources.outline.type', 'official')
            ->assertJsonPath('data.sources.outline.reference', 'Amanah plan 14/2026');
    }

    public function test_editor_validation(): void
    {
        Queue::fake();
        $lat = $this->park->lat;
        $lng = $this->park->lng;

        // Fewer than 3 different points.
        $this->save([[$lng, $lat], [$lng + 0.001, $lat], [$lng, $lat]])->assertUnprocessable()->assertJsonValidationErrors('polygon');
        // A bow tie crosses itself.
        $this->save([[$lng, $lat], [$lng + 0.001, $lat + 0.001], [$lng + 0.001, $lat], [$lng, $lat + 0.001]])
            ->assertUnprocessable()->assertJsonPath('errors.polygon.0', fn ($m) => str_contains($m, 'crosses itself'));
        // Too far from the park (about 3 km east).
        $this->save($this->square($lat, $lng + 0.03, 1500))
            ->assertUnprocessable()->assertJsonPath('errors.polygon.0', fn ($m) => str_contains($m, 'within 2 km'));
        // Not a polygon.
        $this->asUser($this->admin)->putJson("/api/admin/parks/{$this->park->id}/outline", ['polygon' => ['type' => 'Point', 'coordinates' => [[$lng, $lat]]]])
            ->assertUnprocessable();

        $this->assertSame(6000, $this->park->refresh()->area_m2, 'nothing saved');
        Queue::assertNothingPushed();
    }

    public function test_other_companies_and_workers_cannot_edit(): void
    {
        $ring = $this->square($this->park->lat, $this->park->lng, 1500);
        $outsider = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($outsider)->putJson("/api/admin/parks/{$this->park->id}/outline", ['polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]]])->assertNotFound();
        $worker = User::factory()->worker()->create(['company_id' => $this->admin->company_id]);
        $this->asUser($worker)->putJson("/api/admin/parks/{$this->park->id}/outline", ['polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]]])->assertForbidden();
    }

    public function test_sources_are_shown_on_the_park_detail_for_admin_and_municipality(): void
    {
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}")->assertOk()
            ->assertJsonPath('data.sources.outline.type', 'approximate')
            ->assertJsonPath('data.sources.area.type', 'approximate')
            ->assertJsonPath('data.ndvi_recomputing', false);

        $municipality = User::factory()->municipality()->create();
        $this->asUser($municipality)->getJson("/api/municipality/parks/{$this->park->id}")->assertOk()
            ->assertJsonStructure(['data' => ['sources' => ['outline' => ['type', 'reference', 'by', 'at'], 'area' => ['type', 'reference', 'by', 'at']]]]);
    }

    public function test_the_recompute_replaces_the_90_days_and_re_evaluates_the_open_ndvi_drop(): void
    {
        config(['namaa.vision.url' => 'http://vision.test']);
        $this->park->forceFill(['polygon' => ['type' => 'Polygon', 'coordinates' => [$this->square($this->park->lat, $this->park->lng, 1500)]], 'ndvi_recompute_at' => now()])->save();
        // Old values measured on the wrong outline: a drop alert is open.
        foreach ([[30, 0.40], [20, 0.40], [10, 0.40], [2, 0.30]] as [$ago, $mean]) {
            NdviReading::factory()->create(['park_id' => $this->park->id, 'date' => now('Asia/Riyadh')->subDays($ago)->toDateString(), 'mean' => $mean]);
        }
        $old = NdviReading::factory()->create(['park_id' => $this->park->id, 'date' => now('Asia/Riyadh')->subDays(120)->toDateString(), 'mean' => 0.2]);
        $drop = Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::NdviDrop]);

        $values = array_map(fn ($ago) => ['date' => now('Asia/Riyadh')->subDays($ago)->toDateString(), 'mean' => 0.55, 'pixels' => 300], [25, 15, 5, 1]);
        Http::fake(['vision.test/ndvi' => Http::response(['park_id' => $this->park->id, 'values' => $values])]);

        (new RecomputeNdvi($this->park->id))->handle(app(NdviService::class));

        $this->assertSame(4, NdviReading::where('park_id', $this->park->id)->where('date', '>=', now('Asia/Riyadh')->subDays(90)->toDateString())->count());
        $this->assertSame(0, NdviReading::where('park_id', $this->park->id)->where('mean', 0.40)->count(), 'old values replaced');
        $this->assertModelExists($old);
        $this->assertSame('resolved', $drop->refresh()->status->value);
        $this->assertNull($this->park->refresh()->ndvi_recompute_at);
    }

    public function test_a_failed_recompute_keeps_the_old_values(): void
    {
        config(['namaa.vision.url' => 'http://vision.test']);
        $this->park->forceFill(['polygon' => ['type' => 'Polygon', 'coordinates' => [$this->square($this->park->lat, $this->park->lng, 1500)]]])->save();
        NdviReading::factory()->create(['park_id' => $this->park->id, 'date' => now('Asia/Riyadh')->subDays(3)->toDateString()]);
        Http::fake(['vision.test/ndvi' => Http::response(['detail' => 'down'], 500)]);

        try {
            (new RecomputeNdvi($this->park->id))->handle(app(NdviService::class));
            $this->fail('expected VisionUnavailable');
        } catch (\App\Services\VisionUnavailable) {
        }
        $this->assertSame(1, NdviReading::where('park_id', $this->park->id)->count());
    }

    public function test_an_empty_recompute_keeps_the_old_values(): void
    {
        // An outline too small for Sentinel-2's 10 m pixels: the vision service answers no values.
        config(['namaa.vision.url' => 'http://vision.test']);
        $this->park->forceFill(['ndvi_recompute_at' => now()])->save();
        NdviReading::factory()->create(['park_id' => $this->park->id, 'date' => now('Asia/Riyadh')->subDays(3)->toDateString()]);
        Http::fake(['vision.test/ndvi' => Http::response(['park_id' => $this->park->id, 'values' => []])]);

        (new RecomputeNdvi($this->park->id))->handle(app(NdviService::class));

        $this->assertSame(1, NdviReading::where('park_id', $this->park->id)->count(), 'nothing deleted');
        $this->assertNull($this->park->refresh()->ndvi_recompute_at);
        $log = \App\Models\ActivityLog::where('action', 'ndvi.fetched')->latest('id')->firstOrFail();
        $this->assertTrue($log->diff['after']['kept_old']);
        $this->assertSame(0, $log->diff['after']['replaced']);
    }
}
