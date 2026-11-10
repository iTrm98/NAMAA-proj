<?php

namespace Tests\Feature\Cleanup;

use App\Enums\AlertType;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 3.1, A1 and A2: the one-off cleanups. */
class DataCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOFF = '2026-11-10 02:57:00';

    private function offlineAlert(Node $node, string $at): Alert
    {
        $alert = Alert::factory()->resolved()->create([
            'park_id' => $node->park_id, 'node_id' => $node->id, 'type' => AlertType::Offline,
            'data' => ['offline_seconds' => 30],
        ]);
        $alert->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->saveQuietly();

        return $alert;
    }

    private function readingAt(Node $node, string $at): void
    {
        Reading::factory()->create(['node_id' => $node->id, 'created_at' => Carbon::parse($at, 'UTC')]);
    }

    public function test_false_offline_deletes_only_simulated_alerts_with_a_recent_reading_before_the_cutoff(): void
    {
        config(['namaa.cleanup.false_offline_before' => self::CUTOFF]);
        $sim = Node::factory()->simulated()->create();
        $real = Node::factory()->create(['park_id' => $sim->park_id]);

        $this->readingAt($sim, '2026-11-09 20:00:00');
        $false = $this->offlineAlert($sim, '2026-11-09 20:01:05');      // reading 65 s before: false
        $task = Task::factory()->create(['park_id' => $sim->park_id, 'alert_id' => $false->id]);
        $realGap = $this->offlineAlert($sim, '2026-11-09 23:00:00');    // no reading in the 5 min before: kept
        $after = $this->offlineAlert($sim, '2026-11-10 03:10:00');      // after the fix: kept
        $this->readingAt($sim, '2026-11-10 03:09:30');
        $this->readingAt($real, '2026-11-09 20:00:00');
        $realNode = $this->offlineAlert($real, '2026-11-09 20:01:05');  // real node: never touched
        $dry = Alert::factory()->create(['park_id' => $sim->park_id, 'node_id' => $sim->id]);
        $readings = Reading::count();

        $this->artisan('namaa:cleanup:false-offline --dry-run')->expectsOutputToContain('Total: 1 alerts, 1 tasks.')->assertSuccessful();
        $this->assertModelExists($false);
        $this->assertModelExists($task);

        $this->artisan('namaa:cleanup:false-offline')->expectsOutputToContain('Deleted 1 alerts and 1 tasks')->assertSuccessful();
        $this->assertModelMissing($false);
        $this->assertModelMissing($task);
        foreach ([$realGap, $after, $realNode, $dry] as $kept) {
            $this->assertModelExists($kept);
        }
        $this->assertSame($readings, Reading::count());
        $this->assertSame(1, ActivityLog::where('action', 'alert.deleted')->where('subject_id', $false->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'task.deleted')->where('subject_id', $task->id)->count());
    }

    public function test_test_camera_deletes_upload_frames_photos_alerts_and_tasks_but_keeps_captured_frames(): void
    {
        Storage::fake('local');
        $park = Park::factory()->create();
        $camera = Node::factory()->camera()->create(['park_id' => $park->id, 'name' => 'Camera 1', 'last_litter_count' => 8, 'last_seen_at' => now()]);
        $sensor = Node::factory()->create(['park_id' => $park->id]);

        $upload = Detection::factory()->create(['park_id' => $park->id, 'node_id' => $camera->id, 'origin' => 'upload', 'photo_path' => 'camera/2/a.jpg', 'captured_at' => now()->subMinutes(5)]);
        $scenario = Detection::factory()->create(['park_id' => $park->id, 'node_id' => $camera->id, 'origin' => 'scenario', 'photo_path' => 'camera/2/b.jpg', 'captured_at' => now()->subMinutes(4)]);
        $captured = Detection::factory()->create(['park_id' => $park->id, 'node_id' => $camera->id, 'origin' => 'camera', 'photo_path' => 'camera/2/c.jpg', 'count' => 0, 'captured_at' => now()->subMinutes(10)]);
        foreach (['a', 'b', 'c'] as $f) {
            Storage::disk('local')->put("camera/2/$f.jpg", 'jpeg');
        }
        $litter = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $camera->id, 'type' => AlertType::Litter, 'detection_id' => $upload->id]);
        $task = Task::factory()->create(['park_id' => $park->id, 'alert_id' => $litter->id]);
        $keptAlert = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $camera->id, 'type' => AlertType::LitterRepeated, 'detection_id' => $captured->id]);
        $dry = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $sensor->id]);

        $this->artisan('namaa:cleanup:test-camera --dry-run')
            ->expectsOutputToContain('kept: 1')
            ->expectsOutputToContain('Total: 2 detections (with their photos), 1 alerts, 1 tasks.')
            ->assertSuccessful();
        $this->assertModelExists($upload);

        $this->artisan('namaa:cleanup:test-camera')->expectsOutputToContain('Deleted 2 detections, 2 photo files, 1 alerts, 1 tasks.')->assertSuccessful();
        $this->assertModelMissing($upload);
        $this->assertModelMissing($scenario);
        $this->assertModelMissing($litter);
        $this->assertModelMissing($task);
        $this->assertModelExists($captured);
        $this->assertModelExists($keptAlert);
        $this->assertModelExists($dry);
        Storage::disk('local')->assertMissing('camera/2/a.jpg');
        Storage::disk('local')->assertMissing('camera/2/b.jpg');
        Storage::disk('local')->assertExists('camera/2/c.jpg');
        $camera->refresh();
        $this->assertSame(0, $camera->last_litter_count); // from the kept captured frame
        $this->assertSame(2, ActivityLog::where('action', 'detection.deleted')->count());
    }

    public function test_test_camera_refuses_a_node_that_is_not_a_camera(): void
    {
        $sensor = Node::factory()->create();
        $this->artisan("namaa:cleanup:test-camera --node={$sensor->id}")->assertFailed();
    }
}
