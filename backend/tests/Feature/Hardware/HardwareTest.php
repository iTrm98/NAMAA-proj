<?php

namespace Tests\Feature\Hardware;

use App\Enums\AlertType;
use App\Enums\PumpReason;
use App\Jobs\EndRelayTest;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\User;
use App\Services\CameraPipeline;
use App\Services\OfflineChecker;
use App\Services\PumpService;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Camera\CameraTestHelpers;
use Tests\TestCase;

/** Sprint 4, C: test bench, hardware page endpoints, token regeneration, browser camera. */
class HardwareTest extends TestCase
{
    use CameraTestHelpers, RefreshDatabase;

    private Node $sensor;

    private Node $benchSensor;

    private Park $bench;

    private string $tokenFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
        $this->sensor = Node::factory()->withPump()->create(['park_id' => $this->park->id, 'name' => 'Real A']);
        $this->bench = Park::factory()->create(['company_id' => $this->company->id, 'is_test' => true, 'name_en' => 'Workshop (test bench)']);
        $this->benchSensor = Node::factory()->withPump()->create(['park_id' => $this->bench->id, 'name' => 'Bench A']);

        $this->tokenFile = sys_get_temp_dir().'/namaa-tokens-'.uniqid().'.txt';
        config(['namaa.node_tokens_file' => $this->tokenFile]);
    }

    protected function tearDown(): void
    {
        File::delete($this->tokenFile);
        parent::tearDown();
    }

    private function reading(Node $node, int $raw): Reading
    {
        $node->update(['last_seen_at' => now()]);

        return Reading::factory()->create(['node_id' => $node->id, 'park_id' => $node->park_id, 'moisture_raw' => $raw, 'moisture_pct' => 50, 'source' => $node->source]);
    }

    private function otherNode(): Node
    {
        return Node::factory()->create(['park_id' => Park::factory()->create()->id]);
    }

    private function worker(): User
    {
        return User::factory()->worker()->create(['company_id' => $this->company->id]);
    }

    private function device(string $token, array $body = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/device/readings', $body + ['node_id' => $this->sensor->id, 'moisture' => 2000]);
    }

    public function test_bench_setup_is_idempotent_and_hidden(): void
    {
        $this->bench->update(['name_en' => 'Other bench']);
        $this->artisan('namaa:bench:setup')->assertSuccessful();
        $this->artisan('namaa:bench:setup')->expectsOutputToContain('Nothing to do')->assertSuccessful();

        $park = Park::where('name_en', 'Workshop (test bench)')->where('is_test', true)->sole();
        $this->assertSame(['Bench A', 'Bench B', 'Bench Cam'], $park->nodes()->orderBy('id')->pluck('name')->all());
        $this->assertTrue((bool) $park->nodes()->where('name', 'Bench A')->value('has_pump'));
        $this->assertSame('browser', $park->nodes()->where('name', 'Bench Cam')->value('capture_mode'));
        $this->assertCount(2, file($this->tokenFile)); // two sensor tokens, appended once

        $this->asUser($this->admin)->getJson('/api/admin/parks')->assertJsonMissing(['id' => $park->id]);
    }

    public function test_test_parks_are_hidden_unless_include_test(): void
    {
        $ids = fn ($r) => collect($r->json('data'))->pluck('id')->all();

        $this->assertNotContains($this->bench->id, $ids($this->asUser($this->admin)->getJson('/api/admin/parks')));
        $this->assertContains($this->bench->id, $ids($this->asUser($this->admin)->getJson('/api/admin/parks?include_test=1')));
        $this->assertNotContains($this->benchSensor->id, $ids($this->asUser($this->admin)->getJson('/api/admin/nodes')));
        $this->assertContains($this->benchSensor->id, $ids($this->asUser($this->admin)->getJson('/api/admin/nodes?include_test=1')));

        Alert::factory()->create(['park_id' => $this->bench->id, 'node_id' => $this->benchSensor->id]);
        $this->asUser($this->admin)->getJson('/api/admin/alerts')->assertJsonCount(0, 'data');
        $this->asUser($this->admin)->getJson('/api/admin/alerts?include_test=1')->assertJsonCount(1, 'data');

        $impact = $this->asUser($this->admin)->getJson('/api/admin/impact')->json('data.parks');
        $this->assertNotContains($this->bench->id, collect($impact)->pluck('id')->all());

        $viewer = User::factory()->municipality()->create();
        $this->asUser($viewer)->getJson('/api/municipality/parks')->assertJsonMissing(['id' => $this->bench->id]);
        $this->asUser($viewer)->getJson("/api/municipality/parks/{$this->bench->id}")->assertNotFound();
        $this->asUser($viewer)->getJson('/api/municipality/alerts?status=all')->assertJsonCount(0, 'data');
    }

    public function test_bench_nodes_never_raise_offline(): void
    {
        $this->benchSensor->update(['last_seen_at' => null]);
        app(OfflineChecker::class)->run();
        $this->assertFalse(Alert::where('node_id', $this->benchSensor->id)->where('type', AlertType::Offline)->exists());
    }

    public function test_nodes_list_shape_and_access(): void
    {
        $this->reading($this->sensor, 2100);
        $this->otherNode();

        $r = $this->asUser($this->admin)->getJson('/api/admin/nodes');
        $r->assertOk()->assertJsonCount(2, 'data'); // the real sensor and the camera; no bench, no other company
        $row = collect($r->json('data'))->firstWhere('id', $this->sensor->id);
        $this->assertTrue($row['online']);
        $this->assertSame(2100, $row['last_reading']['moisture_raw']);
        $this->assertSame(2100, $row['calibration']['latest_raw']);
        $this->assertSame(['target' => false, 'reported_on' => false, 'test_until' => null], $row['pump']);
        $this->assertSame('url', collect($r->json('data'))->firstWhere('id', $this->camera->id)['camera']['capture_mode']);

        $this->asUser($this->worker())->getJson('/api/admin/nodes')->assertForbidden();
    }

    public function test_calibration_sets_dry_and_wet_from_latest_raw_and_logs(): void
    {
        $this->reading($this->sensor, 3000);
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->sensor->id}/calibration", ['point' => 'dry'])
            ->assertOk()->assertJsonPath('data.calibration.dry_value', 3000);
        $this->reading($this->sensor, 1200);
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->sensor->id}/calibration", ['point' => 'wet'])
            ->assertOk()->assertJsonPath('data.calibration.wet_value', 1200);
        $this->assertSame([3000, 1200], [$this->sensor->fresh()->dry_value, $this->sensor->fresh()->wet_value]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'node.calibrated', 'subject_id' => $this->sensor->id]);
    }

    public function test_calibration_rules(): void
    {
        $url = "/api/admin/nodes/{$this->sensor->id}/calibration";
        $this->asUser($this->admin)->postJson($url, ['point' => 'dry'])->assertUnprocessable()->assertJsonValidationErrors('point'); // no raw yet
        $this->reading($this->sensor, 3500);
        $this->asUser($this->admin)->postJson($url, ['point' => 'wet'])->assertUnprocessable(); // dry must stay above wet
        $this->asUser($this->admin)->postJson($url, ['point' => 'x'])->assertUnprocessable();
        $this->sensor->update(['last_seen_at' => now()->subHour()]);
        $this->asUser($this->admin)->postJson($url, ['point' => 'dry'])->assertUnprocessable(); // stale
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->camera->id}/calibration", ['point' => 'dry'])->assertUnprocessable();
        $this->asUser($this->worker())->postJson($url, ['point' => 'dry'])->assertForbidden();
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->otherNode()->id}/calibration", ['point' => 'dry'])->assertNotFound();
    }

    public function test_relay_test_on_bench_is_direct_and_reaches_the_pump_reply(): void
    {
        Queue::fake();
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->benchSensor->id}/relay-test")
            ->assertOk()->assertJsonPath('data.seconds', 10);

        $this->assertTrue($this->benchSensor->fresh()->pump_target);
        $this->assertDatabaseHas('pump_logs', ['node_id' => $this->benchSensor->id, 'reason' => 'test', 'target' => true]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'node.relay_test']);
        Queue::assertPushed(EndRelayTest::class);

        // The board sees it in the normal reading reply.
        $token = $this->benchSensor->createToken('device', ['device'])->plainTextToken;
        $this->device($token, ['node_id' => $this->benchSensor->id])->assertCreated()->assertJsonPath('pump', true);

        // The delayed job switches it off.
        $id = Cache::get(PumpService::relayTestKey($this->benchSensor->id))['id'];
        (new EndRelayTest($this->benchSensor->id, $id))->handle(app(PumpService::class));
        $this->assertFalse($this->benchSensor->fresh()->pump_target);
    }

    public function test_relay_test_on_a_real_node_needs_confirmation(): void
    {
        Queue::fake();
        $url = "/api/admin/nodes/{$this->sensor->id}/relay-test";
        $this->asUser($this->admin)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertFalse($this->sensor->fresh()->pump_target);
        $this->asUser($this->admin)->postJson($url, ['confirm' => true])->assertOk();
        $this->assertTrue($this->sensor->fresh()->pump_target);
        $this->asUser($this->admin)->postJson($url, ['confirm' => true])->assertUnprocessable(); // already on
    }

    public function test_relay_test_refusals(): void
    {
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->camera->id}/relay-test", ['confirm' => true])->assertUnprocessable();
        $sim = Node::factory()->withPump()->simulated()->create(['park_id' => $this->park->id]);
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$sim->id}/relay-test", ['confirm' => true])->assertUnprocessable();
        $this->asUser($this->worker())->postJson("/api/admin/nodes/{$this->sensor->id}/relay-test")->assertForbidden();
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->otherNode()->id}/relay-test", ['confirm' => true])->assertNotFound();
    }

    public function test_token_regeneration_revokes_old_returns_new_and_replaces_the_file_line(): void
    {
        $old = $this->sensor->createToken('device', ['device'])->plainTextToken;
        $keep = $this->benchSensor->createToken('device', ['device'])->plainTextToken;
        file_put_contents($this->tokenFile, "header\n{$this->sensor->id}\tReal A\tPark\treal\t{$old}\n{$this->benchSensor->id}\tBench A\tPark\treal\t{$keep}\n");

        $r = $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->sensor->id}/token")->assertCreated();
        $new = $r->json('data.token');
        $this->assertNotSame($old, $new);
        $this->assertSame("set node {$this->sensor->id} {$new}", $r->json('data.serial_commands.0'));

        $file = file_get_contents($this->tokenFile);
        $this->assertStringNotContainsString($old, $file);
        $this->assertStringContainsString($new, $file);
        $this->assertStringContainsString($keep, $file); // other lines untouched
        $this->assertSame(1, substr_count($file, "{$this->sensor->id}\tReal A"));

        $log = ActivityLog::where('action', 'node.token_regenerated')->sole();
        $this->assertStringNotContainsString($new, json_encode($log->diff));

        $this->device($old)->assertUnauthorized();
        $this->device($new)->assertCreated();
    }

    public function test_token_regeneration_access(): void
    {
        $this->asUser($this->worker())->postJson("/api/admin/nodes/{$this->sensor->id}/token")->assertForbidden();
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->otherNode()->id}/token")->assertNotFound();
        $this->camera->update(['capture_mode' => 'browser']);
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->camera->id}/token")->assertUnprocessable();
    }

    public function test_camera_mode_switch(): void
    {
        $url = "/api/admin/nodes/{$this->camera->id}/camera";
        $this->asUser($this->admin)->patchJson($url, ['capture_mode' => 'browser'])->assertOk()->assertJsonPath('data.camera.capture_mode', 'browser');
        $this->asUser($this->admin)->patchJson($url, ['capture_mode' => 'url', 'camera_url' => 'http://192.168.137.50'])
            ->assertOk()->assertJsonPath('data.camera.effective_url', 'http://192.168.137.50');
        $this->assertDatabaseHas('activity_logs', ['action' => 'node.camera_updated']);
        $this->asUser($this->admin)->patchJson($url, ['capture_mode' => 'x'])->assertUnprocessable();
        $this->asUser($this->admin)->patchJson($url, ['camera_url' => 'ftp://x'])->assertUnprocessable();
        $this->asUser($this->admin)->patchJson($url, [])->assertUnprocessable();
        $this->asUser($this->admin)->patchJson("/api/admin/nodes/{$this->sensor->id}/camera", ['capture_mode' => 'browser'])->assertUnprocessable();
        $this->asUser($this->worker())->patchJson($url, ['capture_mode' => 'browser'])->assertForbidden();
        $this->asUser($this->admin)->patchJson("/api/admin/nodes/{$this->otherNode()->id}/camera", ['capture_mode' => 'browser'])->assertNotFound();
    }

    public function test_browser_frame_goes_through_the_pipeline_only_for_browser_nodes(): void
    {
        $url = "/api/admin/nodes/{$this->camera->id}/frame";
        $this->litterQueue = [2];
        $accept = ['Accept' => 'application/json'];

        // url node: refused
        $this->asUser($this->admin)->post($url, ['image' => $this->fakeImage('f.png')], $accept)->assertUnprocessable();

        $this->camera->update(['capture_mode' => 'browser']);
        $this->asUser($this->admin)->post($url, ['image' => $this->fakeImage('f.png')], $accept)
            ->assertCreated()->assertJsonPath('data.count', 2);
        $d = Detection::sole();
        $this->assertSame('browser', $d->origin);
        $this->assertSame($this->camera->id, $d->node_id);
        $this->assertNotNull($this->camera->fresh()->last_seen_at);

        $this->fakeServices(visionUp: false);
        $this->asUser($this->admin)->post($url, ['image' => $this->fakeImage('f.png')], $accept)->assertStatus(503);

        $this->asUser($this->admin)->post("/api/admin/nodes/{$this->otherNode()->id}/frame", ['image' => $this->fakeImage('f.png')], $accept)->assertNotFound();
        $this->asUser($this->worker())->post($url, ['image' => $this->fakeImage('f.png')], $accept)->assertForbidden();
    }

    public function test_demo_frame_still_rejects_real_cameras_and_browser_nodes_are_not_polled(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->camera->update(['capture_mode' => 'browser']);
        $this->asUser($this->admin)->post('/api/admin/demo/frame', ['image' => $this->fakeImage('f.png'), 'node_id' => $this->camera->id], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('node_id');

        $this->assertNull(CameraPipeline::cameraNode());
        $this->artisan('namaa:capture')->assertSuccessful();
        $this->assertSame(0, Detection::count());

        // a browser camera that nobody feeds raises no offline alert
        $this->camera->update(['last_seen_at' => null]);
        app(OfflineChecker::class)->run();
        $this->assertFalse(Alert::where('node_id', $this->camera->id)->exists());
    }

    public function test_camera_test_takes_one_capture_without_a_detection(): void
    {
        $url = "/api/admin/nodes/{$this->camera->id}/camera-test";
        $r = $this->asUser($this->admin)->postJson($url)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $r->json('data.image'));
        $this->assertIsInt($r->json('data.ms'));
        $this->assertSame(0, Detection::count());

        $this->fakeServices(cameraUp: false);
        $this->asUser($this->admin)->postJson($url)->assertStatus(503);
        $this->fakeServices();

        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->sensor->id}/camera-test")->assertUnprocessable();
        $this->asUser($this->admin)->postJson("/api/admin/nodes/{$this->otherNode()->id}/camera-test")->assertNotFound();
        $this->asUser($this->worker())->postJson($url)->assertForbidden();
        $this->camera->update(['capture_mode' => 'browser']);
        $this->asUser($this->admin)->postJson($url)->assertUnprocessable();
    }

    public function test_per_node_camera_url_is_polled(): void
    {
        config(['namaa.camera.url' => '']);
        $this->assertNull(CameraPipeline::cameraNode());
        $this->camera->update(['camera_url' => 'http://camera.test']);
        $this->assertSame($this->camera->id, CameraPipeline::cameraNode()->id);
    }

    public function test_offline_check_skips_a_cycle_after_a_scheduler_stall(): void
    {
        $node = Node::factory()->create(['park_id' => $this->park->id, 'last_seen_at' => now()->subMinutes(20)]);

        // stale heartbeat: skipped
        Cache::put(SystemHealth::SCHEDULER_BEAT, now()->subMinutes(10)->toIso8601String());
        $this->assertSame(0, app(OfflineChecker::class)->run());

        // the scheduler is back: beat() remembers the gap, the next cycle is still skipped
        SystemHealth::beat();
        $this->assertSame(0, app(OfflineChecker::class)->run());
        $this->assertFalse(Alert::where('node_id', $node->id)->exists());

        // one cycle later the check runs normally
        $this->travel(61)->seconds();
        SystemHealth::beat();
        $this->assertGreaterThanOrEqual(1, app(OfflineChecker::class)->run());
        $this->assertTrue(Alert::where('node_id', $node->id)->where('type', AlertType::Offline)->exists());
    }

    public function test_pump_reason_test_exists(): void
    {
        $this->assertSame('test', PumpReason::Test->value);
    }
}
