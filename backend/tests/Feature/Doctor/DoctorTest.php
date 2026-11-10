<?php

namespace Tests\Feature\Doctor;

use App\Jobs\QueueHeartbeat;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sprint 3, G: namaa:doctor and the demo page's System health panel. */
class DoctorTest extends TestCase
{
    use RefreshDatabase;

    private bool $visionUp = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['namaa.vision.url' => 'http://vision.test', 'namaa.camera.url' => '', 'app.debug' => false]);
        Http::fake([
            'vision.test/health' => fn () => $this->visionUp
                ? Http::response(['status' => 'ok', 'waste' => ['model' => 'yolo26n.pt', 'custom' => false]])
                : Http::response('down', 500),
            'camera.test/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        SystemHealth::beat();
        (new QueueHeartbeat)->handle();
    }

    /** @return array<string, array{key: string, status: string, detail: string}> */
    private function checks(bool $quick = false): array
    {
        return collect(app(SystemHealth::class)->checks($quick))->keyBy('key')->all();
    }

    public function test_every_check_is_reported(): void
    {
        $checks = $this->checks();

        $this->assertSame(
            ['demo_mode', 'app_debug', 'mysql', 'migrations', 'queue', 'scheduler', 'reverb', 'vision', 'camera', 'real_nodes', 'bench_nodes', 'node_tokens', 'rejected', 'disk'],
            array_keys($checks),
        );
        $this->assertSame('ok', $checks['migrations']['status']);
        $this->assertSame('ok', $checks['queue']['status']);
        $this->assertSame('ok', $checks['scheduler']['status']);
        $this->assertSame('ok', $checks['vision']['status']);
        $this->assertSame('info', $checks['camera']['status']);
        $this->assertSame('ok', $checks['app_debug']['status']);
    }

    public function test_old_or_missing_heartbeats_fail(): void
    {
        $this->travel(4)->minutes();
        $this->assertSame('fail', $this->checks()['queue']['status']);

        Cache::forget(SystemHealth::SCHEDULER_BEAT);
        $this->assertStringContainsString('Scheduler stalled', $this->checks()['scheduler']['detail']);
    }

    public function test_vision_down_and_app_debug_on(): void
    {
        $this->visionUp = false;
        config(['app.debug' => true]);

        $checks = $this->checks();

        $this->assertSame('fail', $checks['vision']['status']);
        $this->assertSame('warn', $checks['app_debug']['status']);
        $this->assertSame('fail', SystemHealth::overall(array_values($checks)));
    }

    public function test_camera_capture_and_node_tokens(): void
    {
        config(['namaa.camera.url' => 'http://camera.test']);
        $park = Park::factory()->create();
        Node::factory()->camera()->create(['park_id' => $park->id, 'last_seen_at' => null]);
        $sensor = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()->subSeconds(20)]);

        $checks = $this->checks();
        $this->assertSame('ok', $checks['camera']['status']);
        $this->assertStringContainsString('image/jpeg', $checks['camera']['detail']);
        Http::assertSent(fn ($r) => $r->url() === 'http://camera.test/capture');
        $this->assertSame('fail', $checks['node_tokens']['status'], 'the sensor has no token yet');

        $sensor->createToken('device', ['device']);
        $this->assertSame('ok', $this->checks()['node_tokens']['status'], 'the camera needs no token');
        $this->assertStringNotContainsString('|', $this->checks()['node_tokens']['detail'], 'no token in the output');

        // The panel does not call /capture: it uses the camera's last frame.
        Http::fake(['camera.test/*' => fn () => $this->fail('quick mode must not call the camera')]);
        $this->assertSame('warn', $this->checks(quick: true)['camera']['status']);
    }

    public function test_command_exit_code_and_json(): void
    {
        $this->artisan('namaa:doctor')->expectsOutputToContain('NAMAA doctor')->assertSuccessful();

        $this->visionUp = false;
        $this->artisan('namaa:doctor')->expectsOutputToContain('FAIL')->assertFailed();
        $this->artisan('namaa:doctor --json')->expectsOutputToContain('"overall": "fail"')->assertFailed();
    }

    public function test_health_panel_endpoint_is_demo_only_and_admin_only(): void
    {
        $admin = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($admin)->getJson('/api/admin/demo/health')->assertNotFound();

        config(['namaa.demo_mode' => true]);
        $this->asUser($admin)->getJson('/api/admin/demo/health')->assertOk()
            ->assertJsonPath('data.overall', 'warn') // reverb is off in tests
            ->assertJsonCount(14, 'data.checks')
            ->assertJsonStructure(['data' => ['checked_at', 'checks' => [['key', 'status', 'detail']]]]);

        $worker = User::factory()->worker()->create(['company_id' => $admin->company_id]);
        $this->asUser($worker)->getJson('/api/admin/demo/health')->assertForbidden();
    }

    // ---- Sprint 4, 0.4: scheduler stall ----

    public function test_scheduler_older_than_90_seconds_is_stalled_and_recovers_by_itself(): void
    {
        $this->assertFalse(SystemHealth::schedulerStatus()['stalled']);

        $this->travel(95)->seconds();
        $status = SystemHealth::schedulerStatus();
        $this->assertTrue($status['stalled']);
        $this->assertGreaterThanOrEqual(95, $status['age_s']);
        $check = $this->checks()['scheduler'];
        $this->assertSame('fail', $check['status']);
        $this->assertStringContainsString('Scheduler stalled', $check['detail']);

        SystemHealth::beat();
        $this->assertFalse(SystemHealth::schedulerStatus()['stalled']);
        $this->assertSame('ok', $this->checks()['scheduler']['status']);
    }

    public function test_the_admin_status_endpoint_shows_the_heartbeat_age(): void
    {
        $admin = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);

        $this->asUser($admin)->getJson('/api/admin/system/status')->assertOk()
            ->assertJsonPath('data.scheduler.stalled', false)
            ->assertJsonStructure(['data' => ['scheduler' => ['heartbeat_at', 'age_s', 'stalled']]]);

        $this->travel(120)->seconds();
        $this->asUser($admin)->getJson('/api/admin/system/status')->assertOk()->assertJsonPath('data.scheduler.stalled', true);

        Cache::forget(SystemHealth::SCHEDULER_BEAT);
        $this->asUser($admin)->getJson('/api/admin/system/status')->assertOk()
            ->assertJsonPath('data.scheduler.stalled', true)->assertJsonPath('data.scheduler.age_s', null);

        $worker = User::factory()->worker()->create(['company_id' => $admin->company_id]);
        $this->asUser($worker)->getJson('/api/admin/system/status')->assertForbidden();
    }
}
