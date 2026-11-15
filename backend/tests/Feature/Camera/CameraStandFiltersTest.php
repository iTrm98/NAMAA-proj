<?php

namespace Tests\Feature\Camera;

use App\Models\Detection;
use App\Services\CameraPipeline;
use App\Services\LitterFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Stand camera: URL config, tray area, 2-of-3 persistence, per-camera threshold, bridge-down state. */
class CameraStandFiltersTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
    }

    private function snapshotUrl(): string
    {
        return "/api/admin/nodes/{$this->camera->id}/snapshot";
    }

    public function test_an_image_url_is_fetched_as_is_and_a_board_url_gets_capture(): void
    {
        $this->camera->update(['camera_url' => 'http://host.docker.internal:8090/frame.jpg']);
        $this->assertSame('http://host.docker.internal:8090/frame.jpg', CameraPipeline::frameUrl($this->camera->fresh()));

        $this->camera->update(['camera_url' => 'http://192.168.137.50/']);
        $this->assertSame('http://192.168.137.50/capture', CameraPipeline::frameUrl($this->camera->fresh()));

        $this->camera->update(['camera_url' => null]);
        config(['namaa.camera.url' => 'http://host.docker.internal:8090/frame.jpg']);
        $this->assertSame('http://host.docker.internal:8090/frame.jpg', CameraPipeline::frameUrl($this->camera->fresh()));
    }

    public function test_capture_polls_the_bridge_url(): void
    {
        $this->camera->update(['camera_url' => 'http://camera.test/frame.jpg']);

        app(CameraPipeline::class)->capture();

        Http::assertSent(fn (Request $r) => $r->url() === 'http://camera.test/frame.jpg');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/frame.jpg/capture'));
    }

    public function test_snapshot_says_camera_offline_when_the_bridge_is_down(): void
    {
        $this->fakeServices(cameraUp: false);

        $this->asUser($this->admin)->getJson($this->snapshotUrl())
            ->assertStatus(503)->assertJsonPath('camera_offline', true);
    }

    public function test_snapshot_is_a_blurred_picture_and_nothing_is_stored(): void
    {
        $this->asUser($this->admin)->getJson($this->snapshotUrl())
            ->assertOk()->assertJsonPath('data.image_w', 160);

        $this->assertSame(0, Detection::count());
    }

    public function test_snapshot_is_refused_when_people_cannot_be_blurred(): void
    {
        $this->fakeServices(visionUp: false);

        $this->asUser($this->admin)->getJson($this->snapshotUrl())
            ->assertStatus(503)->assertJsonPath('vision_offline', true);
    }

    public function test_the_tray_area_is_saved_and_validated(): void
    {
        $url = "/api/admin/nodes/{$this->camera->id}/camera";
        $roi = [[0.1, 0.1], [0.9, 0.1], [0.9, 0.9]];

        $this->asUser($this->admin)->patchJson($url, ['camera_roi' => $roi])->assertOk();
        $this->assertSame($roi, $this->camera->fresh()->camera_roi);

        $this->asUser($this->admin)->patchJson($url, ['camera_roi' => [[0, 0], [1, 1]]])->assertUnprocessable();
        $this->asUser($this->admin)->patchJson($url, ['camera_roi' => [[0, 0], [2, 1], [1, 1]]])->assertUnprocessable();
        $this->asUser($this->admin)->patchJson($url, ['camera_roi' => null])->assertOk();
        $this->assertNull($this->camera->fresh()->camera_roi);
    }

    public function test_detections_outside_the_tray_area_are_ignored(): void
    {
        // Item 0 has its centre at x = 0.11, item 1 at x = 0.23 (frame 160 px wide).
        $this->camera->update(['camera_roi' => [[0, 0], [0.15, 0], [0.15, 1], [0, 1]]]);
        $this->litterQueue = [2];

        $detection = app(CameraPipeline::class)->capture();

        $this->assertSame(1, $detection->count);
        $this->assertCount(1, $detection->items);
    }

    public function test_no_tray_area_means_the_whole_frame(): void
    {
        $this->litterQueue = [2];

        $this->assertSame(2, app(CameraPipeline::class)->capture()->count);
    }

    public function test_an_item_counts_only_when_seen_in_2_of_the_last_3_frames(): void
    {
        config(['namaa.camera.persistence' => ['window' => 3, 'needed' => 2, 'min_iou' => 0.2]]);
        $this->litterQueue = [1, 1, 0, 1, 0];
        $pipeline = app(CameraPipeline::class);

        $counts = collect(range(1, 5))->map(fn () => $pipeline->capture()->count)->all();

        // 1: first sighting. 2: seen twice, confirmed. 3: gone. 4: seen again, and frame 2 is
        // still within the last 3, so it is confirmed. 5: gone.
        $this->assertSame([0, 1, 0, 1, 0], $counts);
        $this->assertCount(1, Detection::orderBy('id')->first()->candidates);
    }

    public function test_a_single_frame_sighting_is_not_litter(): void
    {
        config(['namaa.camera.persistence' => ['window' => 3, 'needed' => 2, 'min_iou' => 0.2]]);
        $this->litterQueue = [1, 0, 0];
        $pipeline = app(CameraPipeline::class);

        $this->assertSame([0, 0, 0], collect(range(1, 3))->map(fn () => $pipeline->capture()->count)->all());
    }

    public function test_the_camera_threshold_overrides_the_default(): void
    {
        $this->litterQueue = [1]; // confidence 0.8
        $this->assertSame(1, app(CameraPipeline::class)->capture()->count);

        $this->camera->update(['litter_threshold' => 0.9]);
        $this->assertSame(0, app(CameraPipeline::class)->capture()->count);

        $this->camera->update(['litter_threshold' => null]);
        config(['namaa.camera.litter_threshold' => 0.85]);
        $this->assertSame(0, app(CameraPipeline::class)->capture()->count);
    }

    public function test_the_default_threshold_is_the_model_threshold(): void
    {
        $this->assertSame(0.185, (float) config('namaa.camera.litter_threshold'));
    }

    public function test_filter_geometry(): void
    {
        $square = [[0.2, 0.2], [0.8, 0.2], [0.8, 0.8], [0.2, 0.8]];
        $this->assertTrue(LitterFilter::insideRoi([90, 90, 110, 110], $square, 200, 200));
        $this->assertFalse(LitterFilter::insideRoi([0, 0, 20, 20], $square, 200, 200));
        $this->assertTrue(LitterFilter::insideRoi([0, 0, 20, 20], null, 200, 200));
        $this->assertEqualsWithDelta(1 / 3, LitterFilter::iou([0, 0, 2, 2], [1, 0, 3, 2]), 0.001);
        $this->assertSame(0.0, LitterFilter::iou([0, 0, 1, 1], [5, 5, 6, 6]));
    }
}
