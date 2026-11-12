<?php

namespace Tests\Feature\Overview;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\ParkStatus;
use App\Models\Alert;
use App\Models\ActivityLog;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\User;
use App\Services\OfflineChecker;
use App\Services\ParkStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6, B1: a real node that never reported is awaiting installation, not offline. */
class AwaitingInstallTest extends TestCase
{
    use RefreshDatabase;

    private function park(DataSource $source = DataSource::Real): Park
    {
        return Park::factory()->create(['status' => ParkStatus::Offline, 'source' => $source]);
    }

    public function test_a_never_reported_real_node_raises_no_offline_alert_and_the_park_awaits_installation(): void
    {
        $park = $this->park();
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null, 'source' => DataSource::Real]);

        $this->assertSame(0, app(OfflineChecker::class)->run());

        $this->assertSame(0, Alert::where('type', AlertType::Offline)->count());
        $this->assertSame(ParkStatus::AwaitingInstall, $park->refresh()->status);
    }

    public function test_a_never_reported_simulated_node_is_still_offline(): void
    {
        $park = $this->park(DataSource::Simulated);
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null, 'source' => DataSource::Simulated]);

        $this->assertSame(1, app(OfflineChecker::class)->run());
        $this->assertSame(ParkStatus::Offline, $park->refresh()->status);
    }

    public function test_the_park_awaits_only_when_all_its_sensor_nodes_await(): void
    {
        $park = $this->park();
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null]);
        Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()]);

        $this->assertSame(ParkStatus::Ok, app(ParkStatusService::class)->compute($park));

        Node::where('park_id', $park->id)->whereNotNull('last_seen_at')->update(['last_seen_at' => now()->subHour()]);
        $this->assertSame(ParkStatus::Offline, app(ParkStatusService::class)->compute($park));
    }

    public function test_after_its_first_reading_normal_offline_rules_apply(): void
    {
        $park = $this->park();
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null]);
        $this->assertTrue($node->isAwaitingInstall());

        $node->update(['last_seen_at' => now()->subMinutes(10)]);
        Reading::factory()->create(['node_id' => $node->id, 'park_id' => $park->id]);

        $this->assertSame(1, app(OfflineChecker::class)->run());
        $this->assertSame(ParkStatus::Offline, $park->refresh()->status);
    }

    public function test_the_cleanup_command_resolves_old_offline_alerts_and_dry_run_changes_nothing(): void
    {
        $park = $this->park();
        $awaiting = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null]);
        $alert = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $awaiting->id, 'type' => AlertType::Offline]);

        $seen = Park::factory()->create();
        $other = Node::factory()->create(['park_id' => $seen->id, 'last_seen_at' => now()->subHour()]);
        $kept = Alert::factory()->create(['park_id' => $seen->id, 'node_id' => $other->id, 'type' => AlertType::Offline]);

        $this->artisan('namaa:cleanup:awaiting-install', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(AlertStatus::Open, $alert->refresh()->status);

        $this->artisan('namaa:cleanup:awaiting-install')->assertSuccessful();

        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status); // resolved, not deleted
        $this->assertSame(AlertStatus::Open, $kept->refresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'alert.resolved', 'subject_id' => $alert->id]);
        $this->assertSame(ParkStatus::AwaitingInstall, $park->refresh()->status);
    }

    public function test_node_status_is_exposed_on_the_nodes_list_and_the_park_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $park = Park::factory()->create(['company_id' => $admin->company_id, 'status' => ParkStatus::AwaitingInstall]);
        $node = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null]);

        $this->asUser($admin)->getJson('/api/admin/nodes')
            ->assertOk()->assertJsonPath('data.0.status', 'awaiting_install')->assertJsonPath('data.0.online', false);
        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}")
            ->assertOk()->assertJsonPath('data.status', 'awaiting_install')
            ->assertJsonPath('data.nodes.0.status', 'awaiting_install');

        $node->update(['last_seen_at' => now()]);
        $this->asUser($admin)->getJson("/api/admin/parks/{$park->id}")->assertJsonPath('data.nodes.0.status', 'online');
    }

    public function test_alerts_by_category_counts_30_days_without_awaiting_install_noise(): void
    {
        $admin = User::factory()->admin()->create();
        $park = Park::factory()->create(['company_id' => $admin->company_id]);
        $awaiting = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => null]);
        $seen = Node::factory()->create(['park_id' => $park->id, 'last_seen_at' => now()]);

        Alert::factory()->create(['park_id' => $park->id, 'node_id' => $seen->id, 'type' => AlertType::Dry]);
        Alert::factory()->resolved()->create(['park_id' => $park->id, 'node_id' => $seen->id, 'type' => AlertType::Leak]);
        Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Litter]);
        Alert::factory()->create(['park_id' => $park->id, 'node_id' => $awaiting->id, 'type' => AlertType::Offline]); // awaiting node
        $cleaned = Alert::factory()->resolved()->create(['park_id' => $park->id, 'node_id' => $seen->id, 'type' => AlertType::Offline]);
        ActivityLog::create(['action' => 'alert.resolved', 'subject_type' => $cleaned->getMorphClass(), 'subject_id' => $cleaned->id,
            'diff' => ['after' => ['reason' => 'awaiting_install']]]);
        $old = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::Dry]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->asUser($admin)->getJson('/api/admin/overview')->assertOk()
            ->assertJsonPath('data.alerts_by_category', ['water' => 1, 'plant' => 1, 'cleanliness' => 1, 'devices' => 0]);
    }
}
