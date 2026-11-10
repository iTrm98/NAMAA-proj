<?php

namespace Tests\Feature\Municipality;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 3, F2: the municipality reads every company and writes nothing. */
class MunicipalityTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Park $parkA;

    private Park $parkB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = User::factory()->municipality()->create();
        $this->parkA = Park::factory()->create(['company_id' => Company::factory()->create(['name' => 'Alpha'])->id]);
        $this->parkB = Park::factory()->simulated()->create(['company_id' => Company::factory()->create(['name' => 'Beta'])->id]);
        Alert::factory()->create(['park_id' => $this->parkA->id]);
        Alert::factory()->create(['park_id' => $this->parkB->id]);
    }

    public function test_it_reads_every_company(): void
    {
        $this->asUser($this->viewer)->getJson('/api/municipality/parks')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.company.name', 'Alpha')
            ->assertJsonPath('data.1.company.name', 'Beta');

        $this->asUser($this->viewer)->getJson("/api/municipality/parks/{$this->parkB->id}")->assertOk()
            ->assertJsonPath('data.company.name', 'Beta')
            ->assertJsonPath('data.source', 'simulated')
            ->assertJsonCount(1, 'data.open_alerts');
        $this->asUser($this->viewer)->getJson("/api/municipality/parks/{$this->parkA->id}/readings?range=1h")->assertOk();
        $this->asUser($this->viewer)->getJson("/api/municipality/parks/{$this->parkA->id}/ndvi")->assertOk();

        $this->asUser($this->viewer)->getJson('/api/municipality/alerts')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['park' => ['company' => ['id', 'name']]]]]);
        $this->asUser($this->viewer)->getJson("/api/municipality/alerts?company_id={$this->parkA->company_id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_impact_has_a_row_per_company(): void
    {
        Task::factory()->done()->create(['park_id' => $this->parkA->id, 'verified' => true, 'time_to_resolve_min' => 20]);

        $data = $this->asUser($this->viewer)->getJson('/api/municipality/impact?days=7')->assertOk()->json('data');

        $this->assertSame(2, $data['alerts']['total']);
        $this->assertSame(['real' => 1, 'simulated' => 1], $data['source_mix']);
        $this->assertCount(2, $data['by_company']);
        $this->assertSame('Alpha', $data['by_company'][0]['company']['name']);
        $this->assertSame(1, $data['by_company'][0]['tasks']['done']);
        $this->assertSame(0, $data['by_company'][1]['tasks']['done']);

        $one = $this->asUser($this->viewer)->getJson("/api/municipality/impact?company_id={$this->parkB->company_id}")->assertOk()->json('data');
        $this->assertSame(1, $one['alerts']['total']);
        $this->assertCount(1, $one['by_company']);
    }

    public function test_camera_frames_are_readable(): void
    {
        Storage::fake('local');
        $camera = Node::factory()->camera()->create(['park_id' => $this->parkA->id]);
        Storage::disk('local')->put('camera/x.jpg', 'jpeg-bytes');
        $detection = Detection::create(['node_id' => $camera->id, 'park_id' => $this->parkA->id, 'photo_path' => 'camera/x.jpg',
            'count' => 1, 'items' => [], 'captured_at' => now(), 'origin' => 'camera']);

        $this->asUser($this->viewer)->getJson("/api/municipality/parks/{$this->parkA->id}/detections")->assertOk()
            ->assertJsonPath('data.0.image_url', "/municipality/detections/{$detection->id}/image");
        $this->asUser($this->viewer)->get("/api/municipality/detections/{$detection->id}/image")->assertOk();
    }

    public function test_the_municipality_group_has_no_write_route(): void
    {
        $writes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/municipality'))
            ->filter(fn ($r) => array_diff($r->methods(), ['GET', 'HEAD']) !== []);

        $this->assertCount(0, $writes, 'municipality routes are GET only');
    }

    public function test_it_cannot_use_admin_or_worker_routes_or_change_anything(): void
    {
        $this->asUser($this->viewer)->getJson('/api/admin/parks')->assertForbidden();
        $this->asUser($this->viewer)->patchJson("/api/admin/parks/{$this->parkA->id}", ['moisture_min' => 10])->assertForbidden();
        $this->asUser($this->viewer)->postJson("/api/admin/parks/{$this->parkA->id}/pump", ['on' => true])->assertForbidden();
        $this->asUser($this->viewer)->postJson('/api/admin/tasks', ['park_id' => $this->parkA->id, 'title' => 'x'])->assertForbidden();
        $this->asUser($this->viewer)->getJson('/api/worker/tasks')->assertForbidden();
        $this->asUser($this->viewer)->postJson("/api/municipality/parks/{$this->parkA->id}")->assertStatus(405);

        $this->assertSame(25, $this->parkA->fresh()->moisture_min);
    }

    public function test_admins_and_workers_cannot_use_municipality_routes(): void
    {
        $admin = User::factory()->admin()->create(['company_id' => $this->parkA->company_id]);
        $this->asUser($admin)->getJson('/api/municipality/parks')->assertForbidden();
        $this->asUser($admin)->getJson('/api/municipality/impact')->assertForbidden();
    }
}
