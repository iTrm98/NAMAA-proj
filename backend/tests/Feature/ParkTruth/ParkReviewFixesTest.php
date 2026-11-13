<?php

namespace Tests\Feature\ParkTruth;

use App\Enums\AlertType;
use App\Enums\MonitoringMode;
use App\Enums\ParkStatus;
use App\Enums\SiteType;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Services\ParkModeService;
use App\Services\StandSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** BE-3 review fixes: detached / satellite_only ingest, stand move re-attach, mode-change cleanup, refusals. */
class ParkReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function postReading(Node $node, array $body)
    {
        $token = $node->createToken('device', ['device'])->plainTextToken;

        return $this->withToken($token)->postJson('/api/device/readings', $body + ['node_id' => $node->id]);
    }

    public function test_detached_node_reading_is_accepted_but_not_evaluated(): void
    {
        $park = Park::factory()->create(['moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Ok, 'auto_irrigation' => true]);
        $node = Node::factory()->withPump()->create(['park_id' => $park->id, 'dry_value' => 3200, 'wet_value' => 1300, 'pump_target' => false, 'detached_at' => now()]);

        // Raw 3200 = 0% moisture: would start the pump and raise an alert on a live node.
        $this->postReading($node, ['moisture' => 3200])->assertSuccessful()->assertJson(['pump' => false]);

        $this->assertSame(0, Reading::count());
        $this->assertSame(0, Alert::count());
        $this->assertFalse((bool) $node->refresh()->pump_target);
        $this->assertNull($park->refresh()->last_reading_at);
    }

    public function test_satellite_only_park_reading_is_accepted_but_not_evaluated(): void
    {
        $park = Park::factory()->create(['moisture_min' => 25, 'moisture_max' => 70, 'monitoring_mode' => MonitoringMode::SatelliteOnly, 'auto_irrigation' => true]);
        $node = Node::factory()->withPump()->create(['park_id' => $park->id, 'dry_value' => 3200, 'wet_value' => 1300, 'pump_target' => false]);

        $this->postReading($node, ['moisture' => 3200])->assertSuccessful()->assertJson(['pump' => false]);

        $this->assertSame(0, Reading::count());
        $this->assertSame(0, Alert::count());
        $this->assertFalse((bool) $node->refresh()->pump_target);
    }

    public function test_satellite_only_turns_auto_irrigation_off(): void
    {
        $park = Park::factory()->simulated()->create(['auto_irrigation' => true, 'status' => ParkStatus::Ok]);
        Node::factory()->simulated()->withPump()->create(['park_id' => $park->id, 'name' => 'Sim 1']);

        app(ParkModeService::class)->apply($park, MonitoringMode::SatelliteOnly);

        $this->assertFalse((bool) $park->refresh()->auto_irrigation);
    }

    public function test_stand_move_reattaches_a_detached_node(): void
    {
        $company = Company::factory()->create();
        $stand = Park::factory()->create(['company_id' => $company->id, 'name_en' => 'Workshop', 'site_type' => SiteType::DemoStand]);
        $other = Park::factory()->create(['company_id' => $company->id]);
        $node = Node::factory()->create(['park_id' => $other->id, 'name' => 'Model A', 'detached_at' => now()]);

        app(StandSetup::class)->run(true, [$node->id]);

        $node->refresh();
        $this->assertSame($stand->id, $node->park_id);
        $this->assertNull($node->detached_at);
    }

    public function test_switching_to_simulated_or_real_resolves_alerts_and_cancels_tasks_when_detaching(): void
    {
        foreach (['simulated', 'real'] as $target) {
            $company = Company::factory()->create();
            $park = Park::factory()->create(['company_id' => $company->id, 'monitoring_mode' => MonitoringMode::RealSensors, 'status' => ParkStatus::Ok]);
            $real = Node::factory()->create(['park_id' => $park->id, 'name' => "Real $target"]);
            $sim = Node::factory()->simulated()->create(['park_id' => $park->id, 'name' => "Sim $target", 'detached_at' => $target === 'real' ? now() : null]);
            if ($target === 'real') {
                $sim->update(['detached_at' => null]);
            }
            $leak = Alert::factory()->create(['park_id' => $park->id, 'node_id' => $real->id, 'type' => AlertType::Leak]);
            $ndvi = Alert::factory()->create(['park_id' => $park->id, 'type' => AlertType::NdviDrop]);
            $task = Task::factory()->create(['park_id' => $park->id, 'alert_id' => $leak->id, 'company_id' => $company->id, 'status' => TaskStatus::Open]);

            app(ParkModeService::class)->apply($park, $target === 'simulated' ? MonitoringMode::Simulated : MonitoringMode::RealSensors);

            $this->assertSame('resolved', $leak->refresh()->status->value, $target);
            $this->assertSame('open', $ndvi->refresh()->status->value, $target);
            $this->assertSame(TaskStatus::Cancelled, $task->refresh()->status, $target);
        }
    }

    public function test_apply_refuses_the_demo_stand_and_archived_parks(): void
    {
        $stand = Park::factory()->create(['site_type' => SiteType::DemoStand]);
        $archived = Park::factory()->create(['archived_at' => now()]);

        try {
            app(ParkModeService::class)->apply($stand, MonitoringMode::SatelliteOnly);
            $this->fail('the stand must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('demo stand', $e->getMessage());
        }
        $this->assertNotSame(MonitoringMode::SatelliteOnly, $stand->refresh()->monitoring_mode);

        $this->expectException(InvalidArgumentException::class);
        app(ParkModeService::class)->apply($archived, MonitoringMode::SatelliteOnly);
    }
}
