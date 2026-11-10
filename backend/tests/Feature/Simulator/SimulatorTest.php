<?php

namespace Tests\Feature\Simulator;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;
use App\Models\User;
use App\Services\ScenarioService;
use App\Services\Simulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SimulatorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /** @var array<string, Node> */
    private array $sim = [];

    private Node $real;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $realPark = Park::factory()->create(['company_id' => $this->company->id]);
        $this->real = Node::factory()->withPump()->create(['park_id' => $realPark->id, 'name' => 'Model A', 'last_moisture_pct' => 40]);

        foreach (['Sim 1' => 21.7, 'Sim 2' => 50.0, 'Sim 3' => 55.0] as $name => $pct) {
            $park = Park::factory()->simulated()->create(['company_id' => $this->company->id, 'moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Ok]);
            $this->sim[$name] = Node::factory()->simulated()->withPump()->create([
                'park_id' => $park->id, 'name' => $name, 'last_moisture_pct' => $pct, 'pump_target' => false,
            ]);
        }
    }

    private function tick(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->travel(5)->seconds();
            app(Simulator::class)->tick();
        }
    }

    private function latest(Node $node): Reading
    {
        return Reading::where('node_id', $node->id)->latest('id')->first();
    }

    public function test_one_simulated_reading_per_simulated_node_and_none_for_real_ones(): void
    {
        $this->artisan('namaa:simulate')->assertSuccessful();

        $this->assertSame(3, Reading::count());
        $this->assertSame(0, Reading::where('node_id', $this->real->id)->count());
        $this->assertSame(3, Reading::where('source', 'simulated')->count());

        foreach ($this->sim as $node) {
            $reading = $this->latest($node);
            $this->assertNotNull($reading->temperature);
            $this->assertGreaterThanOrEqual(26, $reading->temperature);
            $this->assertLessThanOrEqual(39, $reading->temperature);
            $this->assertFalse($reading->pump_on);
            $this->assertSame(0.0, $reading->flow);
            $this->assertNotNull($node->refresh()->last_seen_at);
        }
    }

    public function test_al_adel_on_stage_dries_fast_and_raises_dry_while_healthy_parks_stay_ok(): void
    {
        app(ScenarioService::class)->run('dry'); // 21 %, stage mode
        $this->tick(4);

        $moisture = Reading::where('node_id', $this->sim['Sim 1']->id)->orderBy('id')->pluck('moisture_pct')->all();
        $this->assertLessThan(21.7, $moisture[0]);
        $this->assertLessThan($moisture[0], $moisture[3]);
        $this->assertNotNull(Alert::open()->where('node_id', $this->sim['Sim 1']->id)->where('type', AlertType::Dry)->first());
        $this->assertSame(ParkStatus::Dry, $this->sim['Sim 1']->park->refresh()->status);

        foreach (['Sim 2', 'Sim 3'] as $name) {
            $this->assertSame(ParkStatus::Ok, $this->sim[$name]->park->refresh()->status);
            $pct = $this->latest($this->sim[$name])->moisture_pct;
            $this->assertGreaterThanOrEqual(30, $pct);
            $this->assertLessThanOrEqual(65, $pct);
        }
    }

    public function test_the_pump_raises_moisture_and_the_dry_alert_resolves_on_its_own(): void
    {
        app(ScenarioService::class)->run('dry');
        $this->tick(3);
        $alert = Alert::open()->where('type', AlertType::Dry)->sole();

        $this->sim['Sim 1']->update(['pump_target' => true]);
        $this->tick(1);
        $reading = $this->latest($this->sim['Sim 1']);
        $this->assertTrue($reading->pump_on);
        $this->assertGreaterThanOrEqual(1.5, $reading->flow);
        $this->assertLessThanOrEqual(2.5, $reading->flow);

        $this->tick(5); // stage pump: about +3 % per 5 s from ~21 %
        $this->assertGreaterThan(25, $this->latest($this->sim['Sim 1'])->moisture_pct);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
        $this->assertSame(ParkStatus::Ok, $this->sim['Sim 1']->park->refresh()->status);
    }

    public function test_leak_scenario_raises_a_leak_within_three_ticks_and_recover_ends_it(): void
    {
        $this->artisan('namaa:scenario leak')->assertSuccessful();
        $this->tick(3);

        $node = $this->sim['Sim 2'];
        $this->assertGreaterThan(0.5, $this->latest($node)->flow);
        $this->assertNotNull(Alert::open()->where('node_id', $node->id)->where('type', AlertType::Leak)->first());

        $this->artisan('namaa:scenario recover')->assertSuccessful();
        $this->tick(2);
        $this->assertSame(0.0, $this->latest($node)->flow);
        $this->assertSame(AlertStatus::Resolved, Alert::where('node_id', $node->id)->where('type', AlertType::Leak)->sole()->status);
    }

    public function test_offline_scenario_stops_one_node_until_recover(): void
    {
        $this->artisan('namaa:scenario offline')->assertSuccessful();
        $this->tick(2);

        $this->assertSame(0, Reading::where('node_id', $this->sim['Sim 3']->id)->count());
        $this->assertSame(2, Reading::where('node_id', $this->sim['Sim 2']->id)->count());

        $this->artisan('namaa:scenario recover')->assertSuccessful();
        $this->tick(1);
        $this->assertSame(1, Reading::where('node_id', $this->sim['Sim 3']->id)->count());
    }

    public function test_after_watering_the_stage_ends_and_al_adel_dries_in_real_time_until_the_next_dry_scenario(): void
    {
        $node = $this->sim['Sim 1'];
        app(ScenarioService::class)->run('dry');
        $node->update(['pump_target' => true]);
        $this->tick(4); // ~21 -> ~33 %
        $node->update(['pump_target' => false]);
        $watered = $this->latest($node)->moisture_pct;
        $this->assertGreaterThan(25, $watered);

        $this->tick(20); // on stage it would have lost ~6 %; in real time (100 s) under 0.1 %
        $this->assertFalse(app(ScenarioService::class)->isStaged($node->refresh()));
        $this->assertGreaterThanOrEqual($watered - 1.0, $this->latest($node)->moisture_pct);
        $this->assertNull(Alert::open()->where('node_id', $node->id)->where('type', AlertType::Dry)->first());

        $this->artisan('namaa:scenario dry')->assertSuccessful();
        $this->tick(3);
        $first = $this->latest($node)->moisture_pct;
        $this->tick(3);
        $this->assertLessThan(25, $first);
        $this->assertLessThan($first, $this->latest($node)->moisture_pct); // drying again
    }

    public function test_dry_scenario_drives_al_adel_below_its_minimum_now(): void
    {
        $this->sim['Sim 1']->update(['last_moisture_pct' => 60]);

        $this->artisan('namaa:scenario dry')->assertSuccessful();
        $this->tick(3);

        $this->assertLessThan(25, $this->latest($this->sim['Sim 1'])->moisture_pct);
        $this->assertNotNull(Alert::open()->where('node_id', $this->sim['Sim 1']->id)->where('type', AlertType::Dry)->first());
    }

    public function test_reset_returns_the_simulated_parks_to_the_start_and_never_touches_real_parks(): void
    {
        $alAdel = $this->sim['Sim 1'];
        $alAdel->update(['pump_target' => true, 'last_moisture_pct' => 60]);
        $alAdel->park->update(['auto_irrigation' => true]);
        $simAlert = Alert::factory()->create(['park_id' => $alAdel->park_id, 'node_id' => $alAdel->id]);
        $simTask = Task::factory()->inProgress()->create(['park_id' => $alAdel->park_id]);
        $doneTask = Task::factory()->done()->create(['park_id' => $alAdel->park_id]);
        $realAlert = Alert::factory()->create(['park_id' => $this->real->park_id, 'node_id' => $this->real->id]);
        $realTask = Task::factory()->create(['park_id' => $this->real->park_id]);
        app(ScenarioService::class)->run('leak');

        $summary = app(ScenarioService::class)->run('reset', full: true);

        $this->assertSame(1, $summary['alerts_resolved']);
        $this->assertSame(1, $summary['tasks_cancelled']);
        $this->assertSame(AlertStatus::Resolved, $simAlert->refresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $simTask->refresh()->status);
        $this->assertSame(TaskStatus::Done, $doneTask->refresh()->status);
        $this->assertSame(AlertStatus::Open, $realAlert->refresh()->status);
        $this->assertSame(TaskStatus::Open, $realTask->refresh()->status);
        $this->assertFalse($alAdel->refresh()->pump_target);
        $this->assertFalse($alAdel->park->refresh()->auto_irrigation);
        $this->assertDatabaseHas('pump_logs', ['node_id' => $alAdel->id, 'target' => false, 'reason' => 'system']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'demo.scenario']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task.cancelled', 'subject_id' => $simTask->id]);

        $this->tick(1);
        $this->assertEqualsWithDelta(22, $this->latest($alAdel)->moisture_pct, 1.0);
        $this->assertEqualsWithDelta(50, $this->latest($this->sim['Sim 2'])->moisture_pct, 1.0, 'healthy parks keep their soil');
        $this->assertTrue($this->sim['Sim 2']->park->refresh()->auto_irrigation, 'reset turns automatic irrigation on for healthy parks');
        $this->assertTrue($this->sim['Sim 3']->park->refresh()->auto_irrigation);
        $this->assertSame(0.0, $this->latest($this->sim['Sim 2'])->flow, 'reset also ends a leak');
        $this->tick(2);
        $this->assertNotNull(Alert::open()->where('node_id', $alAdel->id)->where('type', AlertType::Dry)->first());
    }

    public function test_plain_reset_only_resets_the_demo_story_and_leaves_healthy_parks_alone(): void
    {
        $alAdel = $this->sim['Sim 1'];
        $healthy = $this->sim['Sim 2'];
        $healthy->park->update(['auto_irrigation' => false]);
        $healthy->update(['last_moisture_pct' => 12, 'pump_target' => true]);
        $alAdel->park->update(['auto_irrigation' => true]);
        $simAlert = Alert::factory()->create(['park_id' => $healthy->park_id, 'node_id' => $healthy->id]);
        $simTask = Task::factory()->create(['park_id' => $healthy->park_id]);
        app(ScenarioService::class)->run('leak');

        $summary = app(ScenarioService::class)->run('reset');

        $this->assertFalse($summary['full']);
        $this->assertSame(AlertStatus::Resolved, $simAlert->refresh()->status, 'open demo alerts are resolved');
        $this->assertSame(TaskStatus::Cancelled, $simTask->refresh()->status, 'open demo tasks are cancelled');
        $this->assertFalse($alAdel->park->refresh()->auto_irrigation, 'Al Adel is back to manual and dry');
        $this->assertFalse((bool) $healthy->park->refresh()->auto_irrigation, 'the healthy park keeps its irrigation setting');
        $this->assertTrue($healthy->refresh()->pump_target, 'the healthy pump is not switched off');
        $this->assertNull(Cache::get("namaa:sim:{$healthy->id}:leak"), 'forced faults are cleared');
        $this->assertDatabaseMissing('pump_logs', ['node_id' => $healthy->id, 'reason' => 'system']);

        $this->tick(1);
        $this->assertEqualsWithDelta(22, $this->latest($alAdel)->moisture_pct, 1.0);
        $this->assertLessThan(20, $this->latest($healthy)->moisture_pct, 'its soil was not moved back to 52 %');
    }

    public function test_full_reset_through_the_api_and_the_command_option(): void
    {
        config(['namaa.demo_mode' => true]);
        $admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->sim['Sim 2']->park->update(['auto_irrigation' => false]);

        $this->asUser($admin)->postJson('/api/admin/demo/scenario', ['name' => 'reset'])->assertOk()->assertJsonPath('data.full', false);
        $this->assertFalse((bool) $this->sim['Sim 2']->park->refresh()->auto_irrigation);

        $this->asUser($admin)->postJson('/api/admin/demo/scenario', ['name' => 'reset', 'full' => 'maybe'])->assertUnprocessable();
        $this->asUser($admin)->postJson('/api/admin/demo/scenario', ['name' => 'reset', 'full' => true])->assertOk()->assertJsonPath('data.full', true);
        $this->assertTrue((bool) $this->sim['Sim 2']->park->refresh()->auto_irrigation);

        $this->sim['Sim 3']->park->update(['auto_irrigation' => false]);
        $this->artisan('namaa:scenario reset')->assertSuccessful();
        $this->assertFalse((bool) $this->sim['Sim 3']->park->refresh()->auto_irrigation);
        $this->artisan('namaa:scenario reset --full')->assertSuccessful();
        $this->assertTrue((bool) $this->sim['Sim 3']->park->refresh()->auto_irrigation);
    }

    public function test_reset_brings_a_healthy_park_outside_its_range_back(): void
    {
        $this->sim['Sim 2']->update(['last_moisture_pct' => 12]);
        app(ScenarioService::class)->run('reset', full: true);
        $this->tick(1);
        $this->assertEqualsWithDelta(52, $this->latest($this->sim['Sim 2'])->moisture_pct, 1.0);
    }

    // ---- Sprint 3.1: real-time soil physics ----

    /** Simulates $seconds of real time for one node in steps of $step s (no readings stored). */
    private function soilAfter(Node $node, int $seconds, int $step): float
    {
        $sim = app(Simulator::class);
        $scenario = app(ScenarioService::class);
        $scenario->setTrueMoisture($node, 50.0);
        for ($t = 0; $t < $seconds; $t += $step) {
            $node->last_seen_at = now();
            $this->travel($step)->seconds();
            $sim->next($node);
        }

        return $scenario->trueMoisture($node);
    }

    public function test_demo_mode_does_not_change_the_drying_rate(): void
    {
        $node = $this->sim['Sim 2'];
        $this->travelTo(Carbon::parse('2026-11-10 10:30:00', 'UTC')); // 13:30 to 14:30 in Makkah
        $normal = 50.0 - $this->soilAfter($node, 3600, 30);

        config(['namaa.demo_mode' => true]);
        $this->travelTo(Carbon::parse('2026-11-10 10:30:00', 'UTC'));
        $demo = 50.0 - $this->soilAfter($node, 3600, 5);

        $this->assertEqualsWithDelta($normal, $demo, 0.02, '5 s readings dry the soil as fast as 30 s readings');
        $this->assertEqualsWithDelta(2.7, $normal, 0.05, 'about 2.7 %/h around 14:00');
    }

    public function test_drying_follows_the_day_and_averages_the_configured_rate(): void
    {
        $at = fn (string $local) => Carbon::parse("2026-11-10 $local", 'Asia/Riyadh');
        $this->assertEqualsWithDelta(2.7, Simulator::dryingPerHour($at('14:00')), 0.001);
        $this->assertEqualsWithDelta(0.3, Simulator::dryingPerHour($at('02:00')), 0.001);
        $day = 0.0;
        for ($h = 0; $h < 24; $h++) {
            $day += Simulator::dryingPerHour($at(sprintf('%02d:00', $h)));
        }
        $this->assertEqualsWithDelta(36.0, $day, 0.01, 'mean 1.5 %/h');
    }

    public function test_the_real_time_pump_takes_about_12_minutes_from_the_minimum_to_the_middle(): void
    {
        $node = $this->sim['Sim 2'];
        $this->travelTo(Carbon::parse('2026-11-10 08:00:00', 'UTC'));
        $node->pump_target = true;
        $scenario = app(ScenarioService::class);
        $scenario->setTrueMoisture($node, 25.0);
        $sim = app(Simulator::class);
        for ($t = 0; $t < 750; $t += 30) {
            $node->last_seen_at = now();
            $this->travel(30)->seconds();
            $sim->next($node);
        }
        $this->assertEqualsWithDelta(47.5, $scenario->trueMoisture($node), 0.5);
    }

    public function test_a_gap_longer_than_the_max_step_is_not_simulated(): void
    {
        $node = $this->sim['Sim 2'];
        app(ScenarioService::class)->setTrueMoisture($node, 50.0);
        $node->last_seen_at = now();
        $this->travel(3)->hours(); // simulator stopped
        app(Simulator::class)->next($node);
        $this->assertGreaterThan(49.9, app(ScenarioService::class)->trueMoisture($node), 'at most 90 s of drying');
    }

    public function test_unknown_scenario_is_rejected(): void
    {
        $this->artisan('namaa:scenario flood')->assertFailed();
    }

    // ---- Sprint 2.5 ----

    public function test_simulated_battery_for_sim_1_to_3_drops_slowly_and_real_nodes_send_none(): void
    {
        app(ScenarioService::class)->run('reset');
        $this->tick(1);
        $first = $this->latest($this->sim['Sim 1'])->battery_pct;
        $this->assertEqualsWithDelta(98, $first, 0.5);
        $this->assertNull(Reading::where('node_id', $this->real->id)->first(), 'the simulator never writes real nodes');

        // 120 ticks of 30 s (normal mode) = 1 h: about -6 %.
        for ($i = 0; $i < 120; $i++) {
            app(Simulator::class)->tick();
        }
        $this->assertEqualsWithDelta($first - 6, $this->latest($this->sim['Sim 1'])->battery_pct, 0.5);
        $this->assertNotNull($this->latest($this->sim['Sim 3'])->battery_pct);
        $this->assertNull(Alert::open()->where('type', AlertType::LowBattery)->first());
    }

    public function test_heat_scenario_turns_the_open_dry_alert_into_heat_stress_within_one_reading(): void
    {
        config(['namaa.demo_mode' => true]);
        app(ScenarioService::class)->run('reset');
        $this->tick(2);
        $dry = Alert::open()->where('node_id', $this->sim['Sim 1']->id)->where('type', AlertType::Dry)->sole();
        $this->assertSame(TaskPriority::High, $dry->priority);

        $this->artisan('namaa:scenario heat')->assertSuccessful();
        $this->tick(1);

        $dry->refresh();
        $this->assertSame(AlertType::HeatStress, $dry->type);
        $this->assertSame(TaskPriority::Urgent, $dry->priority);
        $this->assertGreaterThanOrEqual(41.5, $this->latest($this->sim['Sim 1'])->temperature);
        $this->assertSame(1, Alert::where('node_id', $this->sim['Sim 1']->id)->count());

        app(ScenarioService::class)->run('recover');
        $this->tick(1);
        $this->assertLessThan(40, $this->latest($this->sim['Sim 1'])->temperature);
        $this->assertSame(AlertType::HeatStress, $dry->refresh()->type, 'stays heat stress until the soil recovers');
    }

    public function test_battery_scenario_raises_low_battery_after_two_readings_and_recover_resolves_it(): void
    {
        config(['namaa.demo_mode' => true]);
        app(ScenarioService::class)->run('reset');
        $this->tick(1);

        app(ScenarioService::class)->run('battery');
        $this->tick(1);
        $this->assertNull(Alert::open()->where('type', AlertType::LowBattery)->first());
        $this->tick(1);
        $alert = Alert::open()->where('type', AlertType::LowBattery)->sole();
        $this->assertSame($this->sim['Sim 1']->id, $alert->node_id);
        $this->assertEqualsWithDelta(15, $this->latest($this->sim['Sim 1'])->battery_pct, 0.3);

        app(ScenarioService::class)->run('recover');
        $this->tick(2);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_blockage_scenario_raises_blockage_after_three_readings_while_the_pump_runs(): void
    {
        config(['namaa.demo_mode' => true]);
        app(ScenarioService::class)->run('reset');
        $node = $this->sim['Sim 1']->refresh();
        $this->assertEquals(2.0, $node->expected_flow_l_min, 'scenarios set the expected flow of simulated pump nodes');
        $this->assertNull($this->real->refresh()->expected_flow_l_min, 'real nodes stay null');

        app(ScenarioService::class)->run('blockage');
        $this->tick(3);
        $this->assertNull(Alert::open()->where('type', AlertType::Blockage)->first(), 'no blockage while the pump is off');

        $node->update(['pump_target' => true]);
        $this->tick(2);
        $this->assertNull(Alert::open()->where('type', AlertType::Blockage)->first());
        $this->tick(1);
        $alert = Alert::open()->where('type', AlertType::Blockage)->sole();
        $this->assertEqualsWithDelta(0.66, $this->latest($node)->flow, 0.06);

        app(ScenarioService::class)->run('recover');
        $this->tick(2);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_the_simulator_never_trips_the_stuck_sensor_rule(): void
    {
        config(['namaa.demo_mode' => true]);
        app(ScenarioService::class)->run('reset');

        // 10 min of 5 s ticks: Al Adel dries to its floor, Sim 2 runs its pump to the top, Sim 3 wanders.
        $this->sim['Sim 2']->update(['pump_target' => true]);
        $this->tick(60);
        $this->sim['Sim 2']->update(['pump_target' => false]);
        $this->tick(60);

        $this->assertLessThan(12, $this->latest($this->sim['Sim 1'])->moisture_pct, 'Al Adel reached its floor');
        $this->assertSame(0, Alert::where('type', AlertType::SensorFault)->count());
    }
}
