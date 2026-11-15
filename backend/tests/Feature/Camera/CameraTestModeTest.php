<?php

namespace Tests\Feature\Camera;

use App\Enums\SiteType;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\NdviReading;
use App\Models\Node;
use App\Models\Task;
use App\Services\CameraMode;
use App\Services\CameraPipeline;
use App\Services\ScenarioService;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Live device page: camera Test mode (no detections, alerts or tasks), the live threshold, the pre-demo readiness check. */
class CameraTestModeTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    private Node $board;

    /** @var callable(): mixed */
    private $bridgeHealth;

    /** @var callable(): mixed */
    private $visionHealth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
        Cache::flush();
        $this->park->update(['site_type' => SiteType::DemoStand, 'is_test' => true]);
        $this->board = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'name' => 'Bench A', 'last_seen_at' => now()]);
        $this->litterQueue = [2];
        $this->bridgeHealth = fn () => Http::response(['ok' => true, 'camera' => 0, 'last_frame_age_ms' => 400]);
        $this->visionHealth = fn () => Http::response(['status' => 'ok', 'waste' => ['model' => 'litter.pt', 'custom' => true, 'classes' => ['litter'], 'confidence' => 0.185, 'imgsz' => 960]]);
        // Faked once; the closures read the properties, so a test can change what the health routes answer.
        Http::fake([
            'bridge.test:8090/health' => fn () => ($this->bridgeHealth)(),
            'vision.test/health' => fn () => ($this->visionHealth)(),
        ]);
    }

    private function frame(?float $threshold = null)
    {
        return $this->asUser($this->admin)->getJson('/api/admin/stand/test-frame'.($threshold !== null ? "?threshold={$threshold}" : ''));
    }

    private function mode(string $mode)
    {
        return $this->asUser($this->admin)->postJson('/api/admin/stand/camera-mode', ['mode' => $mode]);
    }

    public function test_the_mode_defaults_to_demo_and_is_shown_in_the_live_payload(): void
    {
        $this->asUser($this->admin)->getJson('/api/admin/stand/live')->assertOk()
            ->assertJsonPath('data.camera_node.mode', 'demo')
            ->assertJsonPath('data.camera_node.default_threshold', 0.185);

        $this->mode('test')->assertOk()->assertJsonPath('data.camera_node.mode', 'test');
        $this->mode('nonsense')->assertStatus(422);
    }

    public function test_test_mode_creates_no_detections_alerts_or_tasks(): void
    {
        $this->mode('test')->assertOk();
        $this->camera->update(['camera_url' => 'http://camera.test/frame.jpg']);

        $this->frame(0.2)->assertOk()->assertJsonPath('data.total', 2);
        // The scheduled poll does nothing in Test mode either.
        $this->assertNull(app(CameraPipeline::class)->capture());

        $this->assertSame(0, Detection::count());
        $this->assertSame(0, Alert::count());
        $this->assertSame(0, Task::count());
        $this->assertNull($this->camera->fresh()->last_seen_at);
    }

    public function test_demo_mode_still_runs_the_real_flow_and_refuses_test_frames(): void
    {
        $this->frame()->assertStatus(409);

        $this->camera->update(['camera_url' => 'http://camera.test/frame.jpg']);
        app(CameraPipeline::class)->capture();
        $this->assertSame(1, Detection::count());
    }

    public function test_the_threshold_override_reaches_the_vision_service(): void
    {
        $this->mode('test');

        $this->frame(0.31)->assertOk()->assertJsonPath('data.threshold', 0.31);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/waste') && str_contains((string) $r->body(), '0.31'));

        // Without a query the camera's own threshold applies, then the default.
        $this->camera->update(['litter_threshold' => 0.27]);
        $this->frame()->assertOk()->assertJsonPath('data.threshold', 0.27);
        $this->frame(0.9)->assertStatus(422);
        $this->frame(0.05)->assertStatus(422);
    }

    public function test_each_box_says_inside_or_outside_the_tray_and_the_two_of_three_rule(): void
    {
        config(['namaa.camera.persistence' => ['window' => 3, 'needed' => 2, 'min_iou' => 0.2]]);
        // Tray = left half of the 160 px frame: the first box (x 10..25) is inside, the second (30..45) too, so shrink it.
        $this->camera->update(['camera_roi' => [[0, 0], [0.2, 0], [0.2, 1], [0, 1]]]);
        $this->mode('test');

        $first = $this->frame()->assertOk()->json('data');
        $this->assertSame([true, false], array_column($first['items'], 'inside'));
        $this->assertSame([false, false], array_column($first['items'], 'passed'), 'first sighting: 1 of 3');
        $this->assertSame(0, $first['count']);

        $second = $this->frame()->assertOk()->json('data');
        $this->assertSame([true, false], array_column($second['items'], 'passed'), 'second sighting inside the tray: 2 of 3');
        $this->assertSame(1, $second['count']);
        $this->assertTrue($second['tray_set']);
        $this->assertEqualsWithDelta(0.8, $second['top_confidence'], 0.001);
        $this->assertSame(120, $second['inference_ms']);
    }

    public function test_switching_the_mode_forgets_the_previous_test_frames(): void
    {
        config(['namaa.camera.persistence' => ['window' => 3, 'needed' => 2, 'min_iou' => 0.2]]);
        $this->mode('test');
        $this->frame()->assertOk();
        $this->mode('demo');
        $this->mode('test');

        $this->assertSame(0, $this->frame()->json('data.count'), 'a new run starts from 1 of 3');
    }

    public function test_a_camera_or_vision_outage_is_a_503_and_never_a_picture(): void
    {
        $this->mode('test');
        $this->fakeServices(cameraUp: false);
        $this->frame()->assertStatus(503)->assertJsonPath('camera_offline', true);

        $this->fakeServices(visionUp: false);
        $this->frame()->assertStatus(503)->assertJsonPath('vision_offline', true)->assertJsonMissingPath('data');
    }

    public function test_snapshot_saves_the_blurred_frame_and_its_boxes_to_private_storage(): void
    {
        $this->mode('test');
        $this->asUser($this->admin)->postJson('/api/admin/stand/test-snapshot')->assertStatus(422);

        $this->frame()->assertOk();
        $path = $this->asUser($this->admin)->postJson('/api/admin/stand/test-snapshot')->assertCreated()->json('data.path');

        $this->assertStringStartsWith('storage/app/private/test-snapshots/', $path);
        $files = Storage::disk('local')->allFiles('test-snapshots');
        $this->assertCount(4, $files, '_last.jpg + _last.json + the snapshot jpg and json');
        $this->assertSame(0, Detection::count());

        $this->mode('demo');
        $this->asUser($this->admin)->postJson('/api/admin/stand/test-snapshot')->assertStatus(409);
    }

    public function test_scenario_reset_puts_the_camera_back_in_demo_mode(): void
    {
        CameraMode::set(CameraMode::TEST);

        app(ScenarioService::class)->run('reset', null, $this->company->id);

        $this->assertSame('demo', CameraMode::current());
    }

    private function readiness(): array
    {
        return $this->asUser($this->admin)->getJson('/api/admin/stand/readiness')->assertOk()->json('data');
    }

    /** @return array<string, bool> */
    private function byKey(array $data): array
    {
        return array_column($data['checks'], 'ok', 'key');
    }

    private function allGreen(): void
    {
        $this->camera->update(['camera_url' => 'http://bridge.test:8090/frame.jpg', 'camera_roi' => [[0, 0], [1, 0], [1, 1]]]);
        $this->board->update(['last_seen_at' => now()->subSeconds(3)]);
        SystemHealth::beat();
        NdviReading::factory()->for($this->park)->create(['date' => '2026-11-10', 'measured_on' => '2026-11-10']);
    }

    public function test_readiness_is_ready_when_every_part_is_green(): void
    {
        $this->allGreen();

        $data = $this->readiness();

        $this->assertTrue($data['ready'], json_encode($data['checks']));
        $this->assertSame(['board', 'camera', 'vision', 'tray', 'scheduler', 'mode', 'ndvi'], array_column($data['checks'], 'key'));
    }

    public function test_readiness_reports_each_part_that_is_down(): void
    {
        $this->allGreen();

        $this->board->update(['last_seen_at' => now()->subSeconds(40)]);
        $this->assertFalse($this->byKey($this->readiness())['board']);
        $this->board->update(['last_seen_at' => now()]);

        $this->camera->update(['camera_roi' => null]);
        $this->assertFalse($this->byKey($this->readiness())['tray']);
        $this->camera->update(['camera_roi' => [[0, 0], [1, 0], [1, 1]]]);

        Cache::forget(SystemHealth::SCHEDULER_BEAT);
        $this->assertFalse($this->byKey($this->readiness())['scheduler']);
        SystemHealth::beat();

        CameraMode::set(CameraMode::TEST);
        $data = $this->readiness();
        $this->assertFalse($this->byKey($data)['mode']);
        $this->assertFalse($data['ready']);
        CameraMode::set(CameraMode::DEMO);

        NdviReading::query()->delete();
        $this->assertFalse($this->byKey($this->readiness())['ndvi']);
    }

    public function test_readiness_flags_a_wrong_model_a_stale_bridge_and_a_down_vision_service(): void
    {
        $this->allGreen();
        $this->bridgeHealth = fn () => Http::response(['ok' => false, 'camera' => 0, 'last_frame_age_ms' => 9000], 503);
        $this->visionHealth = fn () => Http::response(['status' => 'ok', 'waste' => ['model' => 'yolo26n.pt', 'confidence' => 0.35, 'imgsz' => 640]]);
        $checks = $this->byKey($this->readiness());
        $this->assertFalse($checks['camera']);
        $this->assertFalse($checks['vision']);

        $this->visionHealth = fn () => Http::response('down', 500);
        $this->bridgeHealth = fn () => Http::failedConnection();
        $checks = $this->byKey($this->readiness());
        $this->assertFalse($checks['vision']);
        $this->assertFalse($checks['camera']);
    }
}
