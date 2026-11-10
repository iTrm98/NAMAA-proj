<?php

namespace Tests\Feature\Camera;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Events\DetectionCreated;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Task;
use App\Services\CameraPipeline;
use App\Services\OfflineChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 3, C1: the capture job, privacy blur, offline rule for cameras, retention. */
class CameraCaptureTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
    }

    public function test_capture_stores_a_blurred_frame_and_a_detection(): void
    {
        Event::fake([DetectionCreated::class]);
        $this->litterQueue = [2];

        $this->artisan('namaa:capture')->assertSuccessful();

        $detection = Detection::sole();
        $this->assertSame($this->camera->id, $detection->node_id);
        $this->assertSame($this->park->id, $detection->park_id);
        $this->assertSame(2, $detection->count);
        $this->assertSame('bottle', $detection->items[0]['label']);
        $this->assertSame('yolo26n.pt', $detection->model);
        $this->assertSame(120, $detection->inference_ms);
        $this->assertSame('camera', $detection->origin);
        $this->assertMatchesRegularExpression('#^camera/'.$this->camera->id.'/\d{4}-\d{2}-\d{2}/\d{6}_\d{3}\.jpg$#', $detection->photo_path);
        Storage::disk('local')->assertExists($detection->photo_path);

        $camera = $this->camera->fresh();
        $this->assertNotNull($camera->last_seen_at);
        $this->assertSame(2, $camera->last_litter_count);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://camera.test/capture');
        Http::assertSent(fn (Request $r) => $r->url() === 'http://vision.test/waste' && $r->isMultipart());
        Event::assertDispatched(DetectionCreated::class, fn ($e) => $e->payload['count'] === 2
            && $e->payload['thumbnail_url'] === "/admin/detections/{$detection->id}/image");
    }

    public function test_people_are_blurred_before_the_photo_is_saved(): void
    {
        $this->people = [['confidence' => 0.9, 'box' => [80.0, 20.0, 150.0, 110.0]]];

        $this->artisan('namaa:capture')->assertSuccessful();

        $detection = Detection::sole();
        $this->assertSame(1, $detection->people_count);
        $stored = Storage::disk('local')->get($detection->photo_path);
        $original = CameraTestHelpers::jpeg();

        $this->assertGreaterThan(80, self::contrast($original, 90, 30, 140, 100), 'the checkerboard has high contrast');
        $this->assertLessThan(25, self::contrast($stored, 90, 30, 140, 100), 'inside the person box: blurred');
        $this->assertGreaterThan(80, self::contrast($stored, 5, 30, 60, 100), 'outside the box: untouched');
    }

    public function test_camera_down_logs_a_warning_and_stores_nothing(): void
    {
        Log::spy();
        $this->fakeServices(cameraUp: false);

        $this->artisan('namaa:capture')->assertSuccessful();

        $this->assertSame(0, Detection::count());
        $this->assertNull($this->camera->fresh()->last_seen_at);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Camera did not answer'));
    }

    public function test_vision_down_logs_a_warning_and_stores_nothing(): void
    {
        Log::spy();
        $this->fakeServices(visionUp: false);

        $this->artisan('namaa:capture')->assertSuccessful();

        $this->assertSame(0, Detection::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Vision /waste failed'));
    }

    public function test_empty_camera_url_does_nothing(): void
    {
        config(['namaa.camera.url' => '']);

        $this->artisan('namaa:capture')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, Detection::count());
    }

    public function test_capture_keeps_the_interval_between_runs(): void
    {
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->travel(60)->seconds();
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->assertSame(1, Detection::count(), 'normal mode: one frame per 5 min');

        $this->travel(5)->minutes();
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->assertSame(2, Detection::count());
    }

    public function test_camera_nodes_count_in_the_offline_rule_only_with_a_camera_url(): void
    {
        config(['namaa.camera.url' => '']);
        app(OfflineChecker::class)->run();
        $this->assertSame(0, Alert::where('type', AlertType::Offline)->count(), 'camera off: skipped as before');

        config(['namaa.camera.url' => 'http://camera.test']);
        app(OfflineChecker::class)->run();
        $alert = Alert::where('type', AlertType::Offline)->sole();
        $this->assertSame($this->camera->id, $alert->node_id);

        // The next frame ends it.
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_camera_offline_window_covers_the_capture_interval(): void
    {
        $this->camera->update(['last_seen_at' => now()->subMinutes(6)]);
        app(OfflineChecker::class)->run();
        $this->assertSame(0, Alert::where('type', AlertType::Offline)->count(), '6 min < 2.5 x 5 min capture interval');

        $this->camera->update(['last_seen_at' => now()->subMinutes(13)]);
        app(OfflineChecker::class)->run();
        $this->assertSame(1, Alert::where('type', AlertType::Offline)->count());
    }

    public function test_prune_keeps_frames_attached_to_an_alert_or_task(): void
    {
        $disk = Storage::disk('local');
        $make = function (int $daysAgo, string $name) use ($disk) {
            $disk->put("camera/{$this->camera->id}/x/$name.jpg", 'jpeg');

            return Detection::create(['park_id' => $this->park->id, 'node_id' => $this->camera->id, 'photo_path' => "camera/{$this->camera->id}/x/$name.jpg",
                'count' => 1, 'captured_at' => now()->subDays($daysAgo)]);
        };

        $old = $make(8, 'old');
        $recent = $make(2, 'recent');
        $attached = $make(9, 'attached');
        $alert = Alert::factory()->create(['park_id' => $this->park->id, 'node_id' => $this->camera->id, 'type' => AlertType::Litter, 'detection_id' => $attached->id]);
        Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id]);

        $this->artisan('namaa:prune-camera')->expectsOutputToContain('deleted: 1')->assertSuccessful();

        $this->assertModelMissing($old);
        $disk->assertMissing($old->photo_path);
        $this->assertModelExists($recent);
        $this->assertModelExists($attached);
        $disk->assertExists($attached->photo_path);
    }

    public function test_the_camera_node_is_the_first_camera(): void
    {
        Node::factory()->camera()->create(['park_id' => $this->park->id]);
        $this->assertSame($this->camera->id, CameraPipeline::cameraNode()->id);
    }
}
