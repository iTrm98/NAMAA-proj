<?php

namespace Tests\Feature\Camera;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Services\CameraFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 3, C3/C4: the demo frame upload, the litter scenario, the frames API. */
class CameraApiTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    /** The simulated test camera (Sim Cam): demo frames and the litter scenario go here. */
    private Node $simCam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
        config(['namaa.camera.url' => '']); // the stage fallback: no camera board
        $simPark = Park::factory()->simulated()->create(['company_id' => $this->company->id]);
        $this->simCam = Node::factory()->camera()->simulated()->create(['park_id' => $simPark->id, 'name' => 'Sim Cam', 'last_seen_at' => null]);
    }

    private function upload(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->asUser($this->admin)->post('/api/admin/demo/frame', $extra + [
            'node_id' => $this->simCam->id,
            'image' => UploadedFile::fake()->createWithContent('frame.jpg', self::jpeg()),
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_is_404_outside_demo_mode(): void
    {
        $this->upload()->assertNotFound();
        $this->assertSame(0, Detection::count());
    }

    public function test_upload_goes_through_the_same_pipeline(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->litterQueue = [3];
        $this->people = [['confidence' => 0.9, 'box' => [80.0, 20.0, 150.0, 110.0]]];

        $this->upload(['via' => 'webcam'])->assertCreated()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.origin', 'upload')
            ->assertJsonPath('data.people_count', 1);
        $this->assertNull(Alert::first(), 'one frame');

        $this->travel(10)->seconds();
        $this->upload()->assertCreated();

        $this->assertSame(AlertType::Litter, Alert::sole()->type, 'litter rules ran');
        $detection = Detection::latest('id')->first();
        $this->assertLessThan(25, self::contrast(Storage::disk('local')->get($detection->photo_path), 90, 30, 140, 100), 'blurred');
        $this->assertNotNull($this->simCam->fresh()->last_seen_at);
        $this->assertSame($this->simCam->park_id, Alert::sole()->park_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'demo.frame']);
    }

    public function test_upload_validation_and_vision_down(): void
    {
        config(['namaa.demo_mode' => true]);
        $sensor = Node::factory()->create(['park_id' => $this->park->id]);
        $otherCamera = Node::factory()->camera()->create(['park_id' => Park::factory()->create()->id]);

        $this->upload(['node_id' => $sensor->id])->assertUnprocessable()->assertJsonValidationErrors('node_id');
        $this->upload(['node_id' => $otherCamera->id])->assertUnprocessable()->assertJsonValidationErrors('node_id');
        $this->asUser($this->admin)->post('/api/admin/demo/frame', [
            'node_id' => $this->simCam->id, 'image' => UploadedFile::fake()->createWithContent('x.txt', 'hello'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->fakeServices(visionUp: false);
        $this->upload()->assertStatus(503);
        $this->assertSame(0, Detection::count());
    }

    public function test_a_real_camera_never_receives_demo_frames(): void
    {
        config(['namaa.demo_mode' => true]);

        $this->upload(['node_id' => $this->camera->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('node_id')
            ->assertJsonPath('errors.node_id.0', fn ($m) => str_contains($m, 'is a real camera') && str_contains($m, 'Sim Cam'));
        $this->assertSame(0, Detection::count());
        $this->assertNull($this->camera->fresh()->last_seen_at);
    }

    public function test_workers_cannot_upload(): void
    {
        config(['namaa.demo_mode' => true]);
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->asUser($worker)->post('/api/admin/demo/frame', [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_frames_list_and_image_are_company_scoped(): void
    {
        config(['namaa.camera.url' => 'http://camera.test']);
        $this->frames([1, 0, 2]);

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->park->id}/detections?limit=2")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonStructure(['data' => [['id', 'count', 'items', 'image_url', 'image_w', 'image_h', 'captured_at']]]);

        $detection = Detection::first();
        $this->asUser($this->admin)->get("/api/admin/detections/{$detection->id}/image")->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $outsider = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($outsider)->get("/api/admin/detections/{$detection->id}/image")->assertNotFound();
        $this->asUser($outsider)->getJson("/api/admin/parks/{$this->park->id}/detections")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson("/api/admin/parks/{$this->park->id}/detections")->assertUnauthorized();
    }

    public function test_park_list_shows_the_camera_and_its_last_count(): void
    {
        config(['namaa.camera.url' => 'http://camera.test']);
        $this->frames([3]);

        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk()
            ->assertJsonPath('data.0.camera.node_id', $this->camera->id)
            ->assertJsonPath('data.0.camera.last_count', 3);
    }

    public function test_litter_scenario_says_clearly_when_there_are_no_sample_photos(): void
    {
        config(['namaa.demo_mode' => true, 'namaa.camera.samples_path' => storage_path('framework/testing/samples-empty')]);

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'litter'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'data/samples/litter'));
    }

    public function test_litter_scenario_feeds_the_samples_then_recover_feeds_clean_ones(): void
    {
        config(['namaa.demo_mode' => true]);
        $dir = storage_path('framework/testing/samples-'.uniqid());
        File::ensureDirectoryExists("$dir/litter");
        File::ensureDirectoryExists("$dir/clean");
        File::put("$dir/litter/a.jpg", self::jpeg());
        File::put("$dir/clean/a.jpg", self::jpeg());
        config(['namaa.camera.samples_path' => $dir]);

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'litter'])->assertOk()
            ->assertJsonPath('data.feed', 'litter')->assertJsonPath('data.photos', 1);

        $this->frames([2, 2]); // the queue decides what /waste "sees"; the photos come from the feed
        $this->assertSame(2, Detection::where('origin', 'scenario')->where('node_id', $this->simCam->id)->count());
        $this->assertSame(0, Detection::where('node_id', $this->camera->id)->count(), 'never the real camera');
        $this->assertSame(AlertType::Litter, Alert::sole()->type);

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'recover'])->assertOk()
            ->assertJsonPath('data.camera.feed', 'clean');
        $this->assertSame('clean', app(CameraFeed::class)->current()['kind']);

        $this->asUser($this->admin)->postJson('/api/admin/demo/scenario', ['name' => 'reset'])->assertOk()
            ->assertJsonPath('data.litter_alerts_resolved', 1);
        $this->assertNull(app(CameraFeed::class)->current());

        File::deleteDirectory($dir);
    }

    public function test_the_assigned_worker_sees_the_frame_of_a_litter_task(): void
    {
        config(['namaa.camera.url' => 'http://camera.test']);
        $this->frames([2, 2]);
        $alert = Alert::sole();
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $other = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $task = \App\Models\Task::factory()->create(['park_id' => $this->park->id, 'alert_id' => $alert->id, 'assigned_to' => $worker->id]);

        $this->asUser($worker)->getJson("/api/worker/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.alert.detection.id', $alert->detection_id)
            ->assertJsonPath('data.alert.detection.count', 2);
        $this->asUser($worker)->get("/api/worker/tasks/{$task->id}/frame")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->asUser($other)->get("/api/worker/tasks/{$task->id}/frame")->assertNotFound();
        $this->asUser($worker)->get("/api/admin/detections/{$alert->detection_id}/image")->assertForbidden();
    }
}
