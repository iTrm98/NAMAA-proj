<?php

namespace Tests\Feature\Ndvi;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\GeometrySource;
use App\Enums\MonitoringMode;
use App\Enums\SiteType;
use App\Enums\VerificationStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\NdviComposite;
use App\Models\NdviGrid;
use App\Models\NdviReading;
use App\Models\Park;
use App\Models\User;
use App\Services\NdviBaseline;
use App\Services\NdviConfidence;
use App\Services\NdviRules;
use App\Services\NdviService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sprint 6.2, Phases B and C: confidence, baseline, composite, the ndvi_drop gate, commands, pixels, suggest. */
class NdviV2Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Park $park;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.vision.url' => 'http://vision.test']);
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->park = Park::factory()->create([
            'company_id' => $this->company->id,
            'geometry_source' => GeometrySource::Traced,
            'verification_status' => VerificationStatus::Verified,
            'green_zone' => ['type' => 'MultiPolygon', 'coordinates' => []],
        ]);
    }

    /** @param array<string, mixed> $over */
    private function conf(array $over = []): array
    {
        return NdviConfidence::evaluate($over + [
            'pure_pixels' => 120, 'clear_fraction' => 0.95, 'age_days' => 2, 'dates' => 5, 'mad' => 0.015,
            'traced' => true, 'verified' => true, 'geometry_source' => 'traced', 'measured_on' => 'green_zone', 'inner_width_m' => 80,
        ]);
    }

    // ---- confidence ----

    public function test_a_good_park_is_high_100(): void
    {
        $c = $this->conf();
        $this->assertSame(['score' => 100, 'level' => 'high', 'reasons' => []], $c);
    }

    public function test_fewer_than_8_pure_pixels_is_not_measurable(): void
    {
        $c = $this->conf(['pure_pixels' => 7]);
        $this->assertSame('not_measurable', $c['level']);
        $this->assertNull($c['score']);
        $this->assertSame('too_small', $c['reasons'][0]['code']);
        $this->assertNotSame('not_measurable', $this->conf(['pure_pixels' => 8])['level']);
    }

    public function test_a_26_percent_clear_view_is_low_with_the_partial_view_reason(): void
    {
        $c = $this->conf(['clear_fraction' => 0.26, 'dates' => 1, 'mad' => null]);
        $this->assertSame(['low', 39], [$c['level'], $c['score']]);
        $this->assertSame('partial_view', $c['reasons'][0]['code']);
        $this->assertSame(26, $c['reasons'][0]['params']['pct']);
    }

    public function test_half_clear_single_date_is_medium_some_cloud_and_the_edge_below_it_is_low(): void
    {
        $c = $this->conf(['clear_fraction' => 0.5, 'dates' => 1, 'mad' => null]);
        $this->assertSame(['medium', 65, 'some_cloud'], [$c['level'], $c['score'], $c['reasons'][0]['code']]);
        $this->assertSame('low', $this->conf(['clear_fraction' => 0.49, 'dates' => 1, 'mad' => null])['level']);
    }

    public function test_narrow_parks_are_capped(): void
    {
        $medium = $this->conf(['pure_pixels' => 20, 'inner_width_m' => 26]);
        $this->assertSame(['medium', 69, 'narrow'], [$medium['level'], $medium['score'], $medium['reasons'][0]['code']]);
        $low = $this->conf(['pure_pixels' => 9, 'inner_width_m' => 18]);
        $this->assertSame('low', $low['level']);
    }

    public function test_an_untraced_outline_is_at_most_low_and_an_unchecked_one_at_most_medium(): void
    {
        $untraced = $this->conf(['traced' => false, 'geometry_source' => 'osm', 'measured_on' => 'boundary']);
        $this->assertSame('low', $untraced['level']);
        $this->assertSame('untraced_outline', $untraced['reasons'][0]['code']);

        $unchecked = $this->conf(['verified' => false]);
        $this->assertSame(['medium', 'unverified_outline'], [$unchecked['level'], $unchecked['reasons'][0]['code']]);
    }

    public function test_an_old_image_lowers_the_score_and_over_30_days_caps_at_low(): void
    {
        $this->assertLessThan(100, $this->conf(['age_days' => 20])['score']);
        $this->assertContains('old_image', array_column($this->conf(['age_days' => 20])['reasons'], 'code'));
        $old = $this->conf(['age_days' => 31]);
        $this->assertSame('low', $old['level']);
    }

    public function test_ndvi_words(): void
    {
        $this->assertSame('sparse', NdviConfidence::word(0.19));
        $this->assertSame('moderate', NdviConfidence::word(0.20));
        $this->assertSame('moderate', NdviConfidence::word(0.39));
        $this->assertSame('dense', NdviConfidence::word(0.40));
    }

    // ---- baseline ----

    public function test_baseline_is_the_median_and_mad_of_earlier_values_with_a_z_score(): void
    {
        $h = [];
        foreach ([0.48, 0.50, 0.52, 0.50, 0.46, 0.54] as $i => $m) {
            $h[] = ['date' => now()->subDays(40 + $i * 5)->toDateString(), 'median' => $m];
        }
        $b = NdviBaseline::compute($h, now()->toDateString(), 0.35);
        $this->assertSame(['recent', 6, 0.5, 0.02], [$b['kind'], $b['n'], $b['median'], $b['mad']]);
        $this->assertEqualsWithDelta(-5.06, $b['z'], 0.05); // sigma = 1.4826 x 0.02 = 0.0297
        $this->assertSame(0.3, $b['rel_drop']);
    }

    public function test_fewer_than_4_earlier_values_means_no_baseline(): void
    {
        $h = [];
        foreach ([0.5, 0.5, 0.5] as $i => $m) {
            $h[] = ['date' => now()->subDays(40 + $i * 5)->toDateString(), 'median' => $m];
        }
        $b = NdviBaseline::compute($h, now()->toDateString(), 0.2);
        $this->assertSame(['none', null, null], [$b['kind'], $b['z'], $b['rel_drop']]);
    }

    public function test_the_season_is_used_when_six_values_exist_in_earlier_years(): void
    {
        $asOf = '2026-11-13';
        $h = [];
        foreach ([0, 5, 10, 15, 20, 25] as $i => $d) {
            $h[] = ['date' => now()->setDate(2025, 11, 13)->subDays($d)->toDateString(), 'median' => 0.30];
        }
        $h[] = ['date' => '2026-08-01', 'median' => 0.60];
        $b = NdviBaseline::compute($h, $asOf, 0.30);
        $this->assertSame('seasonal', $b['kind']);
        $this->assertSame(0.3, $b['median']);
    }

    // ---- the drop rule ----

    /** @param array<string, mixed> $over */
    private function composite(array $over = []): NdviComposite
    {
        return NdviComposite::create($over + [
            'park_id' => $this->park->id, 'date' => now('Asia/Riyadh')->subDay()->toDateString(), 'date_from' => now()->subDays(30)->toDateString(),
            'date_to' => now()->toDateString(), 'dates_count' => 4, 'value' => 0.30, 'pure_pixels' => 90, 'baseline_median' => 0.50, 'baseline_mad' => 0.02,
            'baseline_n' => 10, 'baseline_kind' => 'recent', 'z' => -6.7, 'rel_drop' => 0.4, 'confidence' => 90, 'level' => 'high', 'reasons' => [],
        ]);
    }

    private function check(): string
    {
        return app(NdviRules::class)->check($this->park->refresh())['outcome'];
    }

    public function test_high_confidence_far_below_the_baseline_raises_and_a_recovery_resolves(): void
    {
        $c = $this->composite();
        $this->assertSame('raised', $this->check());
        $alert = Alert::where('type', AlertType::NdviDrop)->firstOrFail();
        $this->assertSame(0.4, $alert->data['drop_pct'] / 100);
        $this->assertSame(['high', 4], [$alert->data['level'], $alert->data['dates']]);
        $this->assertSame('still_open', $this->check());

        $c->update(['z' => -0.5, 'rel_drop' => 0.02]);
        $this->assertSame('resolved', $this->check());
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_medium_also_raises_but_a_small_drop_does_not(): void
    {
        $c = $this->composite(['level' => 'medium', 'confidence' => 60, 'z' => -2.0, 'rel_drop' => 0.09]);
        $this->assertSame('ok', $this->check(), '9 % is under the 10 % ratio');
        $c->update(['rel_drop' => 0.10]);
        $this->assertSame('raised', $this->check());
    }

    public function test_never_at_low_confidence_from_a_partial_view_a_single_date_or_no_baseline(): void
    {
        $c = $this->composite(['level' => 'low', 'confidence' => 39, 'reasons' => [NdviConfidence::reason('partial_view', ['pct' => 26])]]);
        $this->assertSame('low_confidence', $this->check());

        $c->update(['level' => 'high', 'dates_count' => 1]);
        $this->assertSame('ok', $this->check(), 'one image is not enough');

        $c->update(['dates_count' => 3, 'baseline_kind' => 'none', 'z' => null, 'rel_drop' => null]);
        $this->assertSame('not_enough_history', $this->check());
        $this->assertSame(0, Alert::count());
    }

    public function test_an_open_alert_is_kept_when_confidence_falls_to_low(): void
    {
        $c = $this->composite();
        $this->check();
        $c->update(['level' => 'low', 'confidence' => 30, 'z' => -0.2, 'rel_drop' => 0.0]);
        $this->assertSame('low_confidence', $this->check());
        $this->assertSame(1, Alert::open()->count());
    }

    public function test_an_untraced_park_never_raises_and_its_open_alert_resolves(): void
    {
        $this->composite();
        $this->check();
        $this->park->update(['geometry_source' => GeometrySource::Osm]);
        $this->assertSame('unverified_outline', $this->check());
        $this->assertSame(0, Alert::open()->count());

        $other = Park::factory()->create(['company_id' => $this->company->id, 'geometry_source' => GeometrySource::RoughLegacy]);
        NdviComposite::create(['park_id' => $other->id, 'date' => now()->toDateString(), 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(), 'dates_count' => 5, 'value' => 0.2, 'baseline_n' => 9, 'baseline_kind' => 'recent', 'z' => -9, 'rel_drop' => 0.6, 'level' => 'high', 'confidence' => 90]);
        $this->assertSame('unverified_outline', app(NdviRules::class)->check($other)['outcome']);
        $this->assertSame(0, Alert::open()->count());
    }

    // ---- fetch, composite, api ----

    /** @param list<array<string, mixed>> $values */
    private function fakeVision(array $values, ?array $composite, array $geometry = [], ?array $pixels = null, string $measuredOn = 'green_zone'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            'vision.test/ndvi/suggest-zone' => Http::response(['draft' => ['type' => 'MultiPolygon', 'coordinates' => [[[[39.8, 21.4], [39.801, 21.4], [39.801, 21.401], [39.8, 21.4]]]]], 'area_m2' => 5200.5, 'pixels' => 52, 'clear_dates' => 6, 'flags' => []]),
            'vision.test/ndvi' => Http::response([
                'park_id' => $this->park->id, 'measured_on' => $measuredOn, 'flags' => [],
                'geometry' => $geometry + ['usual_pixels' => 100.0, 'pure_pixels' => 90, 'inner_width_m' => 80.0],
                'values' => $values, 'dates' => [], 'composite' => $composite, 'reflectance' => null, 'pixels' => $pixels,
            ]),
        ]);
    }

    private function value(int $daysAgo, float $median, float $cf = 0.95): array
    {
        return ['date' => now('Asia/Riyadh')->subDays($daysAgo)->toDateString(), 'mean' => $median, 'median' => $median, 'p90' => $median + 0.1, 'pixels' => 90, 'pure_pixels' => 85,
            'weighted_pixels' => 92.0, 'clear_pixels' => 92.0, 'clear_fraction' => $cf, 'green_cover_pct' => 72.5];
    }

    /** @return array<string, mixed> */
    private function composite30(float $median, int $dates, int $newestAgo = 2): array
    {
        return ['date_from' => now('Asia/Riyadh')->subDays(30)->toDateString(), 'date_to' => now('Asia/Riyadh')->toDateString(), 'dates' => $dates,
            'newest_date' => now('Asia/Riyadh')->subDays($newestAgo)->toDateString(), 'mean' => $median, 'median' => $median, 'p90' => $median + 0.1, 'pixels' => 92, 'pure_pixels' => 85,
            'weighted_pixels' => 92.0, 'clear_pixels' => 92.0, 'clear_fraction' => 0.95, 'green_cover_pct' => 71.3];
    }

    private function pixelsFc(): array
    {
        return ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['v' => 0.43], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[39.8, 21.4], [39.801, 21.4], [39.801, 21.401], [39.8, 21.4]]]]]], 'meta' => ['source' => 'composite', 'pure_only' => false, 'pixels' => 1]];
    }

    public function test_fetch_stores_v2_values_the_composite_the_grid_and_removes_old_v1_rows(): void
    {
        NdviReading::create(['park_id' => $this->park->id, 'date' => now()->subDays(70)->toDateString(), 'mean' => 0.9, 'pixels' => 300]);
        $values = [$this->value(60, 0.50), $this->value(55, 0.51), $this->value(50, 0.49), $this->value(45, 0.50), $this->value(40, 0.52), $this->value(8, 0.44), $this->value(3, 0.46)];
        $this->fakeVision($values, $this->composite30(0.48, 2, 3), pixels: $this->pixelsFc());

        $r = app(NdviService::class)->fetch($this->park, 60);

        $this->assertSame(7, $r['values']);
        $this->assertSame(0, NdviReading::where('method', 'v1')->count());
        $this->assertSame(7, NdviReading::where('method', 'v2')->count());
        $row = NdviReading::where('method', 'v2')->orderByDesc('date')->first();
        $this->assertSame([0.46, 85, 72.5], [$row->median, $row->pure_pixels, $row->green_cover_pct]);
        $c = NdviComposite::firstOrFail();
        $this->assertSame([0.48, 2, 'recent'], [$c->value, $c->dates_count, $c->baseline_kind]);
        $this->assertSame(5, $c->baseline_n);
        $this->assertNotNull($c->level);
        $this->assertSame(1, NdviGrid::count());
        $this->assertSame('ok', $r['rule']['outcome']);

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}")->assertOk()
            ->assertJsonPath('data.satellite.measurable', true)
            ->assertJsonPath('data.satellite.green_cover_pct', 71.3)
            ->assertJsonPath('data.satellite.ndvi', 0.48)
            ->assertJsonPath('data.satellite.ndvi_word', 'dense')
            ->assertJsonPath('data.satellite.age_days', 3)
            ->assertJsonPath('data.satellite.measured_on', 'green_zone')
            ->assertJsonPath('data.satellite.baseline.n', 5)
            ->assertJsonStructure(['data' => ['satellite' => ['confidence' => ['level', 'score', 'reasons'], 'change_vs_normal_pct', 'image_date', 'flags']]]);
        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()->assertJsonPath('data.0.ndvi.mean', 0.48);

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi?days=90")->assertOk()
            ->assertJsonCount(7, 'data')->assertJsonCount(1, 'composites')->assertJsonPath('data.0.method', 'v2');
    }

    public function test_a_park_with_fewer_than_8_pure_pixels_is_not_measurable_and_never_alerts(): void
    {
        $this->fakeVision([$this->value(3, 0.4)], $this->composite30(0.4, 1), ['pure_pixels' => 3]);

        app(NdviService::class)->fetch($this->park, 60);

        $c = NdviComposite::firstOrFail();
        $this->assertSame('not_measurable', $c->level);
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}")->assertOk()
            ->assertJsonPath('data.satellite.measurable', false)
            ->assertJsonPath('data.satellite.ndvi', null)
            ->assertJsonPath('data.satellite.confidence.level', 'not_measurable')
            ->assertJsonPath('data.satellite.confidence.reasons.0.code', 'too_small');
        $this->assertSame(0, Alert::count());
    }

    public function test_an_untraced_park_measured_on_its_boundary_is_low(): void
    {
        $this->park->update(['geometry_source' => GeometrySource::Osm, 'green_zone' => null]);
        $this->fakeVision([$this->value(3, 0.4), $this->value(8, 0.41)], $this->composite30(0.4, 2), measuredOn: 'boundary');

        app(NdviService::class)->fetch($this->park, 60);

        $c = NdviComposite::firstOrFail();
        $this->assertSame('low', $c->level);
        $this->assertSame('untraced_outline', $c->reasons[0]['code']);
    }

    public function test_the_stand_and_archived_parks_are_never_fetched(): void
    {
        $stand = Park::factory()->create(['company_id' => $this->company->id, 'site_type' => SiteType::DemoStand, 'is_test' => true]);
        $archived = Park::factory()->create(['company_id' => $this->company->id, 'archived_at' => now()]);
        $this->fakeVision([], null);

        $this->assertSame('not_a_park', app(NdviService::class)->fetch($stand, 30)['skipped']);
        $this->assertSame('not_a_park', app(NdviService::class)->fetch($archived, 30)['skipped']);
        $this->artisan('namaa:ndvi', ['--park' => $stand->id, '--sync' => true])->assertFailed();
        $this->artisan('namaa:ndvi', ['--park' => 'all', '--sync' => true])->assertSuccessful();

        Http::assertSentCount(1); // only the real park
        $this->assertSame(0, NdviComposite::whereIn('park_id', [$stand->id, $archived->id])->count());
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$stand->id}/ndvi/pixels")->assertNotFound();
    }

    public function test_replace_swaps_the_window_but_keeps_everything_when_the_answer_is_empty(): void
    {
        $this->fakeVision([$this->value(10, 0.5)], $this->composite30(0.5, 1, 10));
        app(NdviService::class)->fetch($this->park, 60);
        $this->fakeVision([$this->value(5, 0.6)], $this->composite30(0.6, 1, 5));
        $r = app(NdviService::class)->fetch($this->park, 60, replace: true);

        $this->assertSame(1, $r['replaced']);
        $this->assertSame([0.6], NdviReading::pluck('median')->all());
        $this->assertSame([0.6], NdviComposite::pluck('value')->all());

        $this->fakeVision([], null);
        $r = app(NdviService::class)->fetch($this->park, 60, replace: true);
        $this->assertTrue($r['kept_old']);
        $this->assertSame(1, NdviReading::count());
        $this->assertSame(1, NdviComposite::whereNotNull('value')->count());
    }

    public function test_no_clear_image_keeps_the_old_composite_and_judges_it_by_its_new_age(): void
    {
        $this->fakeVision([$this->value(25, 0.5)], $this->composite30(0.5, 1, 25));
        app(NdviService::class)->fetch($this->park, 60);
        $before = NdviComposite::firstOrFail()->confidence;

        $this->fakeVision([], null);
        app(NdviService::class)->fetch($this->park, 60);

        $this->assertSame(1, NdviComposite::count());
        $this->assertLessThan($before + 1, NdviComposite::first()->confidence);
        $this->assertSame(0.5, NdviComposite::first()->value);
    }

    // ---- pixels ----

    public function test_pixels_are_served_from_the_stored_grid_without_calling_the_vision_service(): void
    {
        NdviGrid::create(['park_id' => $this->park->id, 'date' => '2026-11-11', 'pixels' => 1, 'geojson' => $this->pixelsFc()]);
        Http::fake();

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi/pixels")->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonPath('features.0.properties.v', 0.43)
            ->assertJsonPath('meta.date', '2026-11-11')
            ->assertJsonPath('meta.pixels', 1);
        Http::assertNothingSent();
    }

    public function test_pixels_are_404_when_no_grid_is_stored_and_never_call_the_vision_service(): void
    {
        Http::fake();
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi/pixels")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_fetch_stores_the_grid_with_pixels_requested(): void
    {
        $this->fakeVision([$this->value(3, 0.4)], $this->composite30(0.4, 1, 3), pixels: $this->pixelsFc());
        app(NdviService::class)->fetch($this->park, 30);
        $this->assertSame(1, NdviGrid::count());
        Http::assertSent(fn ($r) => $r->url() === 'http://vision.test/ndvi' && $r['pixels'] === true);
    }

    public function test_pixels_of_another_company_are_404(): void
    {
        $outsider = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($outsider)->getJson("/api/admin/parks/{$this->park->id}/ndvi/pixels")->assertNotFound();
    }

    // ---- suggest ----

    public function test_suggest_returns_the_draft_and_saves_nothing(): void
    {
        $park = Park::factory()->create(['company_id' => $this->company->id, 'geometry_source' => GeometrySource::Osm, 'green_zone' => null]);
        $this->fakeVision([], null);
        $before = $park->only(['green_zone', 'green_zone_area_m2', 'geometry_source', 'verification_status', 'polygon']);

        $this->asUser($this->admin)->postJson("/api/admin/parks/{$park->id}/green-zone/suggest")->assertOk()
            ->assertJsonPath('data.draft.type', 'MultiPolygon')
            ->assertJsonPath('data.area_m2', 5200.5)
            ->assertJsonPath('data.pixels', 52)
            ->assertJsonPath('data.clear_dates', 6)
            ->assertJsonPath('data.flags', []);

        $this->assertEquals($before, $park->refresh()->only(['green_zone', 'green_zone_area_m2', 'geometry_source', 'verification_status', 'polygon']));
        $this->assertSame(GeometrySource::Osm, $park->geometry_source);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ndvi/suggest-zone') && $r['boundary']['type'] === 'Polygon' && $r['days'] === 60 && $r['ndvi_threshold'] == 0.20);
    }

    public function test_suggest_is_admin_only_company_scoped_and_reports_a_down_service(): void
    {
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $outsider = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->fakeVision([], null);
        $url = "/api/admin/parks/{$this->park->id}/green-zone/suggest";

        $this->asUser($worker)->postJson($url)->assertForbidden();
        $this->asUser($outsider)->postJson($url)->assertNotFound();
        $stand = Park::factory()->create(['company_id' => $this->company->id, 'site_type' => SiteType::DemoStand, 'is_test' => true]);
        $this->asUser($this->admin)->postJson("/api/admin/parks/{$stand->id}/green-zone/suggest")->assertUnprocessable();

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['vision.test/*' => Http::response(['detail' => 'down'], 500)]);
        $this->asUser($this->admin)->postJson($url)->assertStatus(503);
    }

    // ---- commands ----

    public function test_purge_seeded_dry_run_lists_and_writes_nothing_then_deletes_only_the_seeded_rows(): void
    {
        $sim = Park::factory()->create(['company_id' => $this->company->id, 'monitoring_mode' => MonitoringMode::Simulated]);
        $seedTime = '2026-11-08 19:00:00';
        foreach ([1, 2, 3] as $i) {
            DB::table('ndvi_readings')->insert(['park_id' => $sim->id, 'date' => now()->subDays($i * 5)->toDateString(), 'mean' => 0.4, 'pixels' => 300, 'created_at' => $seedTime, 'updated_at' => $seedTime]);
        }
        // a seeded-looking row on a real-sensor park, a refreshed row, and a v2 row: all stay
        DB::table('ndvi_readings')->insert(['park_id' => $this->park->id, 'date' => '2026-10-01', 'mean' => 0.4, 'pixels' => 300, 'created_at' => $seedTime, 'updated_at' => $seedTime]);
        DB::table('ndvi_readings')->insert(['park_id' => $sim->id, 'date' => '2026-10-02', 'mean' => 0.4, 'pixels' => 300, 'created_at' => $seedTime, 'updated_at' => '2026-11-12 03:10:07']);
        DB::table('ndvi_readings')->insert(['park_id' => $sim->id, 'date' => '2026-10-03', 'mean' => 0.4, 'pixels' => 300, 'method' => 'v2', 'created_at' => $seedTime, 'updated_at' => $seedTime]);

        $this->artisan('namaa:ndvi:purge-seeded', ['--dry-run' => true])
            ->expectsOutputToContain("park #{$sim->id}: 3 row(s)")->expectsOutputToContain('3 row(s) would be deleted')->assertSuccessful();
        $this->assertSame(6, NdviReading::count());

        $this->artisan('namaa:ndvi:purge-seeded')->expectsOutputToContain('3 row(s) deleted')->assertSuccessful();
        $this->assertSame(3, NdviReading::count());
        $this->assertSame(0, NdviReading::where('park_id', $sim->id)->where('method', 'v1')->whereColumn('created_at', 'updated_at')->count());
    }

    public function test_close_unverified_dry_run_writes_nothing_then_closes_with_the_note(): void
    {
        $rough = Park::factory()->create(['company_id' => $this->company->id, 'geometry_source' => GeometrySource::RoughLegacy]);
        $bad = Alert::factory()->create(['park_id' => $rough->id, 'type' => AlertType::NdviDrop]);
        $good = Alert::factory()->create(['park_id' => $this->park->id, 'type' => AlertType::NdviDrop]);

        $this->artisan('namaa:ndvi:close-unverified', ['--dry-run' => true])
            ->expectsOutputToContain("Would close alert #{$bad->id}")->expectsOutputToContain('dry run, nothing changed')->assertSuccessful();
        $this->assertSame(AlertStatus::Open, $bad->refresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'alert.resolved']);

        $this->artisan('namaa:ndvi:close-unverified')->expectsOutputToContain('1 closed: Raised on an unverified outline')->assertSuccessful();
        $this->assertSame(AlertStatus::Resolved, $bad->refresh()->status);
        $this->assertSame(AlertStatus::Open, $good->refresh()->status, 'a traced outline keeps its alert');
        $log = \App\Models\ActivityLog::where('action', 'alert.resolved')->firstOrFail();
        $this->assertSame('Raised on an unverified outline', $log->diff['after']['reason'] ?? null);
    }
}
