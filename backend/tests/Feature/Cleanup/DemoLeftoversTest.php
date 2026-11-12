<?php

namespace Tests\Feature\Cleanup;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Services\CameraFeed;
use App\Services\CameraImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** namaa:cleanup:demo-leftovers: test tasks, Sim Cam frames that are not from data/samples, simulated parks only. */
class DemoLeftoversTest extends TestCase
{
    use RefreshDatabase;

    private string $samples;

    private Park $simPark;

    private Node $simCam;

    private Park $realPark;

    private Node $realCam;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->samples = sys_get_temp_dir().'/namaa-samples-'.uniqid();
        File::ensureDirectoryExists($this->samples.'/litter');
        File::put($this->samples.'/litter/01.jpg', 'sample-bytes');
        config(['namaa.camera.samples_path' => $this->samples]);

        $this->simPark = Park::factory()->simulated()->create();
        $this->simCam = Node::factory()->camera()->simulated()->create(['park_id' => $this->simPark->id, 'name' => 'Sim Cam']);
        $this->realPark = Park::factory()->create(['company_id' => $this->simPark->company_id]);
        $this->realCam = Node::factory()->camera()->create(['park_id' => $this->realPark->id, 'name' => 'Camera 1']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->samples);
        parent::tearDown();
    }

    private function frame(Node $node, ?string $sample): Detection
    {
        $path = "camera/{$node->id}/".uniqid().'.jpg';
        app(CameraImages::class)->store($path, $this->jpeg());

        return Detection::factory()->create([
            'park_id' => $node->park_id, 'node_id' => $node->id, 'photo_path' => $path, 'origin' => $sample ? 'scenario' : 'upload', 'sample' => $sample,
        ]);
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function test_it_cancels_test_tasks_and_removes_only_non_sample_frames_of_simulated_cameras(): void
    {
        $good = $this->frame($this->simCam, CameraFeed::sampleId('litter', $this->samples.'/litter/01.jpg'));
        $stale = $this->frame($this->simCam, 'litter/01.jpg@0000000000'); // the photo was replaced since
        $old = $this->frame($this->simCam, null);                          // dinner-table photo of an old test
        $real = $this->frame($this->realCam, null);                        // real camera: never touched

        $litter = Alert::factory()->create(['park_id' => $this->simPark->id, 'node_id' => $this->simCam->id, 'type' => AlertType::Litter, 'detection_id' => $old->id]);
        $litterTask = Task::factory()->create(['park_id' => $this->simPark->id, 'alert_id' => $litter->id, 'title' => 'Pick up litter']);
        $goodAlert = Alert::factory()->create(['park_id' => $this->simPark->id, 'node_id' => $this->simCam->id, 'type' => AlertType::Litter, 'detection_id' => $good->id]);

        $dry = Alert::factory()->create(['park_id' => $this->simPark->id, 'type' => AlertType::Dry]);
        $regression = Task::factory()->create(['park_id' => $this->simPark->id, 'alert_id' => $dry->id, 'title' => 'Escalation regression']);
        $byNote = Task::factory()->create(['park_id' => $this->simPark->id, 'note' => 'made by the e2e run']);
        $normal = Task::factory()->create(['park_id' => $this->simPark->id, 'title' => 'Irrigate the park']);
        $latest = Task::factory()->create(['park_id' => $this->simPark->id, 'title' => 'Latest check']); // "test" inside a word: kept
        $realDry = Alert::factory()->create(['park_id' => $this->realPark->id, 'type' => AlertType::Dry]);
        $realTask = Task::factory()->create(['park_id' => $this->realPark->id, 'alert_id' => $realDry->id, 'title' => 'Escalation regression']);

        // dry run: nothing changes
        $this->artisan('namaa:cleanup:demo-leftovers --dry-run')->expectsOutputToContain('Nothing changed')->assertSuccessful();
        $this->assertSame(4, Detection::count());
        $this->assertSame(TaskStatus::Open, $regression->refresh()->status);

        $this->artisan('namaa:cleanup:demo-leftovers')->assertSuccessful();

        $this->assertSame(TaskStatus::Cancelled, $regression->refresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $byNote->refresh()->status);
        $this->assertSame(AlertStatus::Resolved, $dry->refresh()->status, 'the alert the test left open is resolved');
        $this->assertSame(TaskStatus::Open, $normal->refresh()->status);
        $this->assertSame(TaskStatus::Open, $latest->refresh()->status);

        $this->assertModelExists($good);
        $this->assertModelMissing($stale);
        $this->assertModelMissing($old);
        $this->assertModelMissing($litter);
        $this->assertModelMissing($litterTask);
        $this->assertModelExists($goodAlert);
        $this->assertFalse(Storage::disk('local')->exists($old->photo_path));
        $this->assertFalse(Storage::disk('local')->exists(CameraImages::variantPath($old->photo_path, 'thumb')));
        $this->assertTrue(Storage::disk('local')->exists($good->photo_path));

        // real parks and nodes untouched
        $this->assertModelExists($real);
        $this->assertTrue(Storage::disk('local')->exists($real->photo_path));
        $this->assertSame(TaskStatus::Open, $realTask->refresh()->status);
        $this->assertSame(AlertStatus::Open, $realDry->refresh()->status);

        // idempotent
        $this->artisan('namaa:cleanup:demo-leftovers')->expectsOutputToContain('0 tasks cancelled, 0 alerts resolved, 0 frames')->assertSuccessful();
    }
}
