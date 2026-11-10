<?php

namespace Tests\Feature\Municipality;

use App\Events\AlertRaised;
use App\Events\ReadingReceived;
use App\Events\TaskCreated;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Services\AlertService;
use App\Enums\AlertType;
use App\Services\PumpService;
use App\Support\DeviceRejections;
use App\Support\Realtime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Sprint 4 review fixes: test-bench parks stay away from the municipality; relay-test interval; scoped health. */
class TestParkIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function names(object $event): array
    {
        return array_map(fn (PrivateChannel $c) => $c->name, $event->broadcastOn());
    }

    public function test_municipality_gets_404_for_test_park_data(): void
    {
        $viewer = User::factory()->municipality()->create();
        $park = Park::factory()->create(['company_id' => Company::factory()->create()->id, 'is_test' => true]);
        $detection = Detection::factory()->create(['park_id' => $park->id]);

        foreach (['readings?range=1h', 'ndvi', 'detections'] as $path) {
            $this->asUser($viewer)->getJson("/api/municipality/parks/{$park->id}/$path")->assertNotFound();
        }
        $this->asUser($viewer)->getJson("/api/municipality/detections/{$detection->id}/image")->assertNotFound();
    }

    public function test_test_park_events_skip_the_municipality_channel(): void
    {
        $test = new AlertRaised(1, [], toMunicipality: false);
        $live = new AlertRaised(1, []);
        $this->assertSame(['private-company.1'], $this->names($test));
        $this->assertSame(['private-company.1', 'private-municipality'], $this->names($live));

        // The services pass the flag from the park.
        \Illuminate\Support\Facades\Event::fake([AlertRaised::class, ReadingReceived::class, TaskCreated::class]);
        $park = Park::factory()->create(['company_id' => Company::factory()->create()->id, 'is_test' => true]);
        app(AlertService::class)->raise($park, null, AlertType::NdviDrop, AlertType::NdviDrop->severity(), []);
        \Illuminate\Support\Facades\Event::assertDispatched(AlertRaised::class, fn ($e) => $e->toMunicipality === false);
    }

    public function test_reading_reply_uses_the_fast_interval_during_a_relay_test(): void
    {
        config(['namaa.demo_mode' => false]);
        $park = Park::factory()->create(['company_id' => Company::factory()->create()->id]);
        $node = Node::factory()->create(['park_id' => $park->id]);
        $token = $node->createToken('device', ['device'])->plainTextToken;
        $post = fn () => $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $node->id, 'moisture' => 2000]);

        $post()->assertCreated()->assertJsonPath('interval_s', 30);
        $this->app['auth']->forgetGuards();

        Cache::put(PumpService::relayTestKey($node->id), ['id' => 'x', 'until' => now()->addSeconds(10)->toIso8601String()], 60);
        $post()->assertCreated()->assertJsonPath('interval_s', 5);
    }

    public function test_demo_health_shows_only_the_own_companys_rejection(): void
    {
        config(['namaa.demo_mode' => true]);
        $admin = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $otherPark = Park::factory()->create(['company_id' => Company::factory()->create()->id]);
        $otherNode = Node::factory()->create(['park_id' => $otherPark->id]);
        DeviceRejections::record($otherNode->id, 'x', 422, 'secret-ish message');

        $detail = fn () => collect($this->asUser($admin)->getJson('/api/admin/demo/health')->json('data.checks'))->firstWhere('key', 'rejected')['detail'];
        $this->assertStringNotContainsString("node {$otherNode->id}", $detail());
        $this->assertStringContainsString('No rejected', $detail());

        $park = Park::factory()->create(['company_id' => $admin->company_id]);
        $node = Node::factory()->create(['park_id' => $park->id]);
        DeviceRejections::record($node->id, 'y', 403, 'mine');
        $this->assertStringContainsString("node {$node->id}", $detail());
    }
}
