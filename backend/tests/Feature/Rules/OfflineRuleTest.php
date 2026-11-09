<?php

namespace Tests\Feature\Rules;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Services\OfflineChecker;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineRuleTest extends TestCase
{
    use RefreshDatabase;

    private function park(): Park
    {
        return Park::factory()->create(['status' => ParkStatus::Ok]);
    }

    private function offlineAlerts(Node $node)
    {
        return Alert::where('node_id', $node->id)->where('type', AlertType::Offline);
    }

    public function test_a_never_seen_node_counts_as_offline(): void
    {
        $park = $this->park();
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null]);

        $this->assertSame(1, app(OfflineChecker::class)->run());

        $alert = $this->offlineAlerts($node)->sole();
        $this->assertSame('warning', $alert->severity->value);
        $this->assertNull($alert->data['last_seen_at']);
        $this->assertSame(ParkStatus::Offline, $park->refresh()->status);
        $this->assertNotNull($park->status_changed_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.raised', 'subject_id' => $alert->id]);
    }

    public function test_a_stale_node_raises_one_alert_and_the_park_goes_offline(): void
    {
        $park = $this->park();
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()->subMinutes(6)]);

        $this->artisan('namaa:check-offline')->assertSuccessful();

        $this->assertSame(1, $this->offlineAlerts($node)->open()->count());
        $this->assertSame(ParkStatus::Offline, $park->refresh()->status);
    }

    public function test_no_duplicate_alert_on_the_next_run(): void
    {
        $park = $this->park();
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()->subMinutes(6)]);
        $checker = app(OfflineChecker::class);

        $checker->run();
        $changedAt = $park->refresh()->status_changed_at;
        $this->travel(1)->minutes();
        $this->assertSame(0, $checker->run());

        $this->assertSame(1, $this->offlineAlerts($node)->count());
        $this->assertEquals($changedAt, $park->refresh()->status_changed_at);
    }

    public function test_a_park_with_one_of_two_sensor_nodes_online_stays_online(): void
    {
        $park = $this->park();
        $stale = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()->subMinutes(10)]);
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()]);

        app(OfflineChecker::class)->run();

        $this->assertSame(1, $this->offlineAlerts($stale)->count());
        $this->assertSame(ParkStatus::Ok, $park->refresh()->status);
    }

    public function test_camera_nodes_are_skipped(): void
    {
        $park = $this->park();
        Node::factory()->camera()->create(['park_id' => $park->id, 'last_seen_at' => null]);
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()]);

        $this->assertSame(0, app(OfflineChecker::class)->run());
    }

    public function test_the_next_reading_resolves_the_alert_and_the_park_comes_back(): void
    {
        $park = $this->park();
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()->subMinutes(6)]);
        app(OfflineChecker::class)->run();
        $this->assertSame(ParkStatus::Offline, $park->refresh()->status);

        app(ReadingIngestor::class)->ingest($node->fresh(), ['moisture' => 2250]); // 50 %

        $alert = $this->offlineAlerts($node)->sole();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame(ParkStatus::Ok, $park->refresh()->status);
    }

    public function test_demo_threshold_is_30_seconds_normal_is_5_minutes(): void
    {
        $park = $this->park();
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()->subSeconds(60)]);

        config(['namaa.demo_mode' => false]);
        $this->assertSame(0, app(OfflineChecker::class)->run());

        config(['namaa.demo_mode' => true]);
        $this->assertSame(1, app(OfflineChecker::class)->run());
        $this->assertSame(30, $this->offlineAlerts($node)->sole()->data['offline_seconds']);
    }
}
