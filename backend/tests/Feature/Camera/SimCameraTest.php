<?php

namespace Tests\Feature\Camera;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Services\OfflineChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 3.1, B: the simulated test camera. */
class SimCameraTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_sim_cam_once_on_al_adel_with_a_device_token(): void
    {
        $park = Park::factory()->simulated()->create(['name_en' => 'Al Adel Park']);

        $this->artisan('namaa:sim:camera')->expectsOutputToContain('Created Sim Cam')->assertSuccessful();
        $this->artisan('namaa:sim:camera')->expectsOutputToContain('Nothing to do')->assertSuccessful();

        $node = Node::sole();
        $this->assertSame([$park->id, NodeType::Camera, DataSource::Simulated, 'Sim Cam'], [$node->park_id, $node->type, $node->source, $node->name]);
        $this->assertSame(1, $node->tokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'node.created', 'subject_id' => $node->id]);
    }

    public function test_it_refuses_a_real_park(): void
    {
        Park::factory()->create(['name_en' => 'Al Adel Park']);
        $this->artisan('namaa:sim:camera')->assertFailed();
        $this->assertSame(0, Node::count());
    }

    public function test_sim_cam_is_never_polled_or_marked_offline(): void
    {
        config(['namaa.camera.url' => 'http://camera.test']);
        $simCam = Node::factory()->camera()->simulated()->create(['last_seen_at' => null]);

        app(OfflineChecker::class)->run();

        $this->assertFalse(Alert::where('node_id', $simCam->id)->where('type', AlertType::Offline)->exists());
        $this->assertNull(\App\Services\CameraPipeline::cameraNode());
    }
}
