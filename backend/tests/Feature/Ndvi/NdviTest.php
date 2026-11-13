<?php

namespace Tests\Feature\Ndvi;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Jobs\FetchNdvi;
use App\Models\Alert;
use App\Models\Company;
use App\Models\NdviReading;
use App\Models\Park;
use App\Models\User;
use App\Services\NdviService;
use App\Services\VisionUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sprint 3, D: NDVI fetch (job, command, demo button), the ndvi_drop rule and the API. */
class NdviTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Park $park;

    /** What the fake /ndvi answers. @var list<array{date: string, mean: float, pixels: int}> */
    private array $values = [];

    /** @var list<array<string, mixed>> */
    private array $dates = [];

    private bool $visionUp = true;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.vision.url' => 'http://vision.test']);
        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->park = Park::factory()->create(['company_id' => $this->company->id]);
        $this->assertNotEmpty($this->park->polygon, 'the factory gives parks an outline');

        Http::fake([
            'vision.test/ndvi' => function () {
                $this->calls++;

                return $this->visionUp
                    ? Http::response(['park_id' => $this->park->id, 'measured_on' => 'boundary', 'flags' => [], 'geometry' => ['usual_pixels' => 40.0, 'pure_pixels' => 30, 'inner_width_m' => 90.0], 'values' => array_map(fn ($v) => $v + ['median' => $v['mean'], 'p90' => $v['mean'], 'pure_pixels' => 30, 'weighted_pixels' => 40.0, 'clear_pixels' => 40.0, 'clear_fraction' => 1.0, 'green_cover_pct' => 60.0], $this->values), 'dates' => $this->dates, 'composite' => null, 'pixels' => null])
                    : Http::response(['detail' => 'STAC timed out'], 504);
            },
        ]);
    }

    /** @param array<string, float> $byDate */
    private function history(array $byDate): void
    {
        foreach ($byDate as $date => $mean) {
            NdviReading::create(['park_id' => $this->park->id, 'date' => $date, 'mean' => $mean, 'pixels' => 300]);
        }
    }

    public function test_fetch_upserts_and_running_it_twice_changes_nothing(): void
    {
        $this->values = [
            ['date' => now()->subDays(6)->toDateString(), 'mean' => 0.41, 'pixels' => 310],
            ['date' => now()->subDays(1)->toDateString(), 'mean' => 0.43, 'pixels' => 305],
        ];

        $first = app(NdviService::class)->fetch($this->park, 10);
        $this->assertSame(['values' => 2, 'inserted' => 2, 'updated' => 0], array_intersect_key($first, array_flip(['values', 'inserted', 'updated'])));

        $this->values[1]['mean'] = 0.44; // Copernicus reprocessed a scene
        $second = app(NdviService::class)->fetch($this->park, 10);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(2, $second['updated']);
        $this->assertSame(2, NdviReading::count());
        $this->assertSame(0.44, NdviReading::orderByDesc('date')->first()->mean);
        $this->assertDatabaseHas('activity_logs', ['action' => 'ndvi.fetched']);

        Http::assertSent(fn ($r) => $r->url() === 'http://vision.test/ndvi'
            && $r['park_id'] === $this->park->id
            && $r['boundary']['type'] === 'Polygon' && $r['green_zone'] === null && $r['composite_days'] === 30 && $r['shrink_m'] == 5
            && $r['date_to'] === now('Asia/Riyadh')->toDateString());
    }

    public function test_the_job_rethrows_a_vision_failure_so_the_queue_retries_it(): void
    {
        $job = new FetchNdvi($this->park->id, 10);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
        $this->assertGreaterThan((int) config('namaa.vision.ndvi_timeout_seconds'), $job->timeout);

        $this->visionUp = false;
        try {
            $job->handle(app(NdviService::class));
            $this->fail('expected VisionUnavailable');
        } catch (VisionUnavailable) {
        }
        $this->assertSame(0, NdviReading::count());

        // The retry succeeds.
        $this->visionUp = true;
        $this->values = [['date' => now()->subDay()->toDateString(), 'mean' => 0.5, 'pixels' => 200]];
        $job->handle(app(NdviService::class));
        $this->assertSame(1, NdviReading::count());
    }

    public function test_a_run_counts_done_and_finally_failed_parks(): void
    {
        $other = Park::factory()->create(['company_id' => $this->company->id]);
        $run = NdviService::startRun([$this->park->id, $other->id], 90);
        $this->values = [['date' => now()->subDay()->toDateString(), 'mean' => 0.5, 'pixels' => 200]];

        (new FetchNdvi($this->park->id, 90, $run['id']))->handle(app(NdviService::class));
        (new FetchNdvi($other->id, 90, $run['id']))->failed(new VisionUnavailable('down'));

        $state = NdviService::currentRun();
        $this->assertSame(1, $state['done']);
        $this->assertSame(1, $state['failed']);
        $this->assertSame(1, $state['values']);
        $this->assertNotNull($state['finished_at']);
    }

    public function test_admin_and_municipality_read_the_history_oldest_first(): void
    {
        $this->history([
            now()->subDays(100)->toDateString() => 0.3,
            now()->subDays(20)->toDateString() => 0.4,
            now()->subDays(5)->toDateString() => 0.5,
        ]);

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.mean', 0.4)
            ->assertJsonPath('data.1.source', 'sentinel-2');
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi?days=10")->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi?days=0")->assertUnprocessable();
        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()
            ->assertJsonPath('data.0.ndvi.mean', 0.5)
            ->assertJsonPath('data.0.ndvi.date', now()->subDays(5)->toDateString());

        $outsider = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($outsider)->getJson("/api/admin/parks/{$this->park->id}/ndvi")->assertNotFound();

        $municipality = User::factory()->municipality()->create();
        $this->asUser($municipality)->getJson("/api/municipality/parks/{$this->park->id}/ndvi?days=200")->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($municipality)->getJson('/api/municipality/parks')->assertOk()->assertJsonPath('data.0.ndvi.mean', 0.5);
    }

    public function test_demo_button_queues_one_job_per_park_and_reports_progress(): void
    {
        Queue::fake();
        Park::factory()->create(); // another company

        $this->asUser($this->admin)->postJson('/api/admin/demo/ndvi')->assertNotFound(); // demo mode off

        config(['namaa.demo_mode' => true]);
        $this->asUser($this->admin)->postJson('/api/admin/demo/ndvi')->assertStatus(202)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.days', 90);
        Queue::assertPushed(FetchNdvi::class, 1);
        Queue::assertPushed(FetchNdvi::class, fn (FetchNdvi $job) => $job->parkId === $this->park->id && $job->runId !== null);

        $this->asUser($this->admin)->postJson('/api/admin/demo/ndvi')->assertStatus(409);
        $this->asUser($this->admin)->getJson('/api/admin/demo/ndvi')->assertOk()->assertJsonPath('data.done', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'demo.ndvi']);
    }

    public function test_command_queues_by_default_and_runs_now_with_sync(): void
    {
        Queue::fake();
        $this->artisan('namaa:ndvi')->expectsOutputToContain('Queued NDVI for 1 park(s)')->assertSuccessful();
        Queue::assertPushed(FetchNdvi::class, fn (FetchNdvi $job) => $job->days === 35);

        $this->values = [['date' => now()->subDays(3)->toDateString(), 'mean' => 0.42, 'pixels' => 250]];
        $this->artisan('namaa:ndvi', ['--days' => 90, '--park' => $this->park->id, '--sync' => true])
            ->expectsOutputToContain('1 values (1 new)')->assertSuccessful();
        Http::assertSent(fn ($r) => $r['date_from'] === now('Asia/Riyadh')->subDays(90)->toDateString());

        $this->visionUp = false;
        $this->artisan('namaa:ndvi', ['--sync' => true])->assertFailed();
    }

    public function test_vision_client_sends_the_configured_max_cloud(): void
    {
        $this->artisan('namaa:ndvi', ['--park' => $this->park->id, '--sync' => true])->assertSuccessful();
        Http::assertSent(fn ($r) => $r['max_cloud'] === 80);

        config(['namaa.ndvi.max_cloud' => 55]);
        $this->artisan('namaa:ndvi', ['--park' => $this->park->id, '--sync' => true])->assertSuccessful();
        Http::assertSent(fn ($r) => $r['max_cloud'] === 55);
    }

    public function test_sync_command_prints_every_date_kept_or_dropped_with_the_reason(): void
    {
        $this->values = [['date' => '2026-11-11', 'mean' => 0.312, 'pixels' => 412]];
        $this->dates = [
            ['date' => '2026-11-06', 'status' => 'dropped', 'reason' => 'too_few_pixels', 'pixels' => 4],
            ['date' => '2026-11-08', 'status' => 'dropped', 'reason' => 'outside_outline', 'pixels' => 0],
            ['date' => '2026-11-09', 'status' => 'dropped', 'reason' => 'no_data', 'pixels' => 0],
            ['date' => '2026-11-11', 'status' => 'kept', 'mean' => 0.312, 'pixels' => 412],
        ];
        $this->artisan('namaa:ndvi', ['--park' => $this->park->id, '--sync' => true])
            ->expectsOutputToContain('2026-11-11  kept     NDVI 0.31  412 clear px')
            ->expectsOutputToContain('2026-11-06  dropped  no clear pixel after the cloud mask')
            ->expectsOutputToContain('2026-11-08  dropped  outside the outline')
            ->expectsOutputToContain('2026-11-09  dropped  no data')
            ->assertSuccessful();
    }

    // ---- Sprint 4, 0.3: outlines too small for Sentinel-2 ----

    public function test_the_api_marks_too_small_values_and_hides_their_mean_on_the_park(): void
    {
        NdviReading::create(['park_id' => $this->park->id, 'date' => now()->subDay()->toDateString(), 'mean' => 0.31, 'pixels' => 19]);

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/ndvi")->assertOk()
            ->assertJsonPath('data.0.too_small', true)
            ->assertJsonPath('data.0.pixels', 19);
        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()
            ->assertJsonPath('data.0.ndvi.too_small', true)
            ->assertJsonPath('data.0.ndvi.mean', null)
            ->assertJsonPath('data.0.ndvi.pixels', 19);
        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}")->assertOk()->assertJsonPath('data.ndvi.too_small', true);
        $this->asUser($this->admin)->getJson('/api/admin/impact')->assertOk()->assertJsonPath('data.parks.0.ndvi.too_small', true);

        $municipality = User::factory()->municipality()->create();
        $this->asUser($municipality)->getJson('/api/municipality/parks')->assertOk()->assertJsonPath('data.0.ndvi.mean', null);
    }

    public function test_exactly_20_pixels_is_measurable(): void
    {
        NdviReading::create(['park_id' => $this->park->id, 'date' => now()->subDay()->toDateString(), 'mean' => 0.31, 'pixels' => 20]);

        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()
            ->assertJsonPath('data.0.ndvi.too_small', false)
            ->assertJsonPath('data.0.ndvi.mean', 0.31);
    }
}
