<?php

namespace Tests\Feature\Stand;

use App\Enums\PumpReason;
use App\Enums\SiteType;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\User;
use App\Services\Calibration;
use App\Services\PumpService;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 6.2, Phase G: the demo stand board (reply shape, manual pump, auto-off, calibration, live endpoints). */
class StandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Park $stand;

    private Node $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->stand = Park::factory()->create([
            'company_id' => $this->company->id, 'site_type' => SiteType::DemoStand, 'is_test' => true,
            'name_en' => 'NAMAA demo stand', 'auto_irrigation' => true, 'moisture_min' => 25, 'moisture_max' => 70,
        ]);
        $this->board = Node::factory()->withPump()->create([
            'park_id' => $this->stand->id, 'name' => 'Bench A', 'pump_target' => false, 'last_seen_at' => now(),
            'dry_value' => 3000, 'wet_value' => 1000, 'calibrated_dry_at' => now(), 'calibrated_wet_at' => now(),
        ]);
    }

    private function report(array $body = []): \Illuminate\Testing\TestResponse
    {
        $token = $this->board->createToken('device', ['device'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/device/readings', $body + ['node_id' => $this->board->id]);
    }

    private function pumpOn(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['on' => true])->assertOk();
    }

    public function test_the_stand_reply_has_no_local_rule_and_a_two_second_interval(): void
    {
        $this->report(['moisture_raw' => 2000, 'pump_on' => false, 'link' => 'usb'])
            ->assertCreated()
            ->assertExactJson(['pump' => false, 'interval_s' => 2, 'pump_max_s' => 30]);

        $this->assertSame('usb', $this->board->fresh()->via);
        $this->assertSame(2000, $this->board->readings()->sole()->moisture_raw);
    }

    public function test_a_normal_park_node_still_gets_its_local_rule(): void
    {
        $park = Park::factory()->create(['company_id' => $this->company->id]);
        $node = Node::factory()->withPump()->create(['park_id' => $park->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($node->createToken('device', ['device'])->plainTextToken)
            ->postJson('/api/device/readings', ['node_id' => $node->id, 'moisture' => 2000])
            ->assertCreated()->assertJsonStructure(['pump', 'interval_s', 'local_rule']);
    }

    public function test_the_stand_pump_is_manual_only(): void
    {
        // A very dry soil would start a park pump (auto irrigation is on and the alert opens after 2 readings).
        foreach (range(1, 6) as $i) {
            $this->travel(2)->seconds();
            $this->report(['moisture_raw' => 2950, 'pump_on' => false])->assertCreated()->assertJsonPath('pump', false);
        }

        $this->assertFalse($this->board->fresh()->pump_target);
        $this->assertSame(0, PumpLog::count());
    }

    public function test_the_pump_switches_off_after_the_max_run_time(): void
    {
        $this->pumpOn();
        $this->travel(31)->seconds();
        $this->board->update(['last_seen_at' => now()]); // still checking in

        $this->assertSame(1, app(PumpService::class)->standWatchdog());

        $this->assertFalse($this->board->fresh()->pump_target);
        $this->assertSame(PumpReason::AutoOffMax, PumpLog::latest('id')->first()->reason);
    }

    public function test_the_pump_stays_on_inside_the_limit_while_the_board_checks_in(): void
    {
        $this->pumpOn();
        $this->travel(8)->seconds();
        $this->board->update(['last_seen_at' => now()]);

        $this->assertSame(0, app(PumpService::class)->standWatchdog());
        $this->assertTrue($this->board->fresh()->pump_target);
    }

    public function test_the_pump_switches_off_when_the_board_stops_checking_in(): void
    {
        $this->pumpOn();
        $this->travel(11)->seconds();

        $this->assertSame(1, app(PumpService::class)->standWatchdog());

        $this->assertFalse($this->board->fresh()->pump_target);
        $this->assertSame(PumpReason::AutoOffNoCheckin, PumpLog::latest('id')->first()->reason);
    }

    public function test_a_reboot_does_not_bring_the_pump_back(): void
    {
        $this->pumpOn();
        $this->travel(3)->seconds();

        $this->report(['moisture_raw' => 2000, 'pump_on' => false, 'boot' => true])
            ->assertCreated()->assertJsonPath('pump', false);

        $this->assertFalse($this->board->fresh()->pump_target);
        $this->assertSame(PumpReason::AutoOffNoCheckin, PumpLog::latest('id')->first()->reason);
    }

    public function test_a_reading_after_a_long_gap_clears_the_target_too(): void
    {
        $this->pumpOn();
        $this->travel(12)->seconds();

        $this->report(['moisture_raw' => 2000, 'pump_on' => false])->assertCreated()->assertJsonPath('pump', false);
        $this->assertFalse($this->board->fresh()->pump_target);
    }

    public function test_a_smaller_uptime_counts_as_a_reboot(): void
    {
        $this->board->update(['uptime_s' => 500]);
        $this->pumpOn();
        $this->travel(2)->seconds();

        $this->report(['moisture_raw' => 2000, 'uptime_s' => 3])->assertCreated()->assertJsonPath('pump', false);
    }

    public function test_calibration_math_and_the_equal_guard(): void
    {
        $cal = app(Calibration::class);
        $node = new Node(['dry_value' => 3000, 'wet_value' => 1000]);

        $this->assertSame(50.0, $cal->moisturePct($node, 2000));
        $this->assertSame(0.0, $cal->moisturePct($node, 3500)); // clamped
        $this->assertSame(100.0, $cal->moisturePct($node, 500));

        $equal = new Node(['dry_value' => 2000, 'wet_value' => 2000]);
        $this->assertNull($cal->moisturePct($equal, 2000));
        $this->assertSame(['raw' => 2000, 'pct' => null, 'calibrated' => false], $cal->describe($equal, 2000));
        $this->assertSame(['raw' => 2000, 'pct' => 50.0, 'calibrated' => true], $cal->describe($node, 2000));
    }

    public function test_the_stand_soil_is_not_calibrated_until_both_points_are_recorded(): void
    {
        $this->board->update(['calibrated_wet_at' => null]);

        $this->report(['moisture_raw' => 2000])->assertCreated();

        $this->assertNull($this->board->readings()->sole()->moisture_pct);
        $this->asUser($this->admin)->getJson('/api/admin/stand/live')->assertOk()
            ->assertJsonPath('data.moisture.raw', 2000)->assertJsonPath('data.moisture.pct', null)
            ->assertJsonPath('data.moisture.calibrated', false)->assertJsonPath('data.calibration.calibrated', false);
    }

    public function test_live_payload(): void
    {
        $this->report(['moisture_raw' => 2000, 'pump_on' => false, 'link' => 'wifi', 'uptime_s' => 10])->assertCreated();
        $this->pumpOn();

        $this->asUser($this->admin)->getJson('/api/admin/stand/live')->assertOk()
            ->assertJsonPath('data.node_id', $this->board->id)
            ->assertJsonPath('data.source', 'real')
            ->assertJsonPath('data.board.online', true)->assertJsonPath('data.board.link', 'wifi')
            ->assertJsonPath('data.moisture.pct', 50)->assertJsonPath('data.moisture.calibrated', true)
            ->assertJsonPath('data.pump.target', true)->assertJsonPath('data.pump.reported_on', false)
            ->assertJsonPath('data.pump.max_s', 30)
            ->assertJsonCount(1, 'data.trend')
            ->assertJsonPath('data.camera', null)
            ->assertJsonStructure(['data' => ['pump' => ['cutoff_at', 'reason', 'by'], 'calibration' => ['raw_dry', 'raw_wet', 'dry_at', 'wet_at']]]);
    }

    public function test_the_countdown_is_close_to_the_max_run_time(): void
    {
        $this->pumpOn();

        $left = $this->asUser($this->admin)->getJson('/api/admin/stand/live')->json('data.pump.remaining_s');
        $this->assertGreaterThan(20, $left);
        $this->assertLessThanOrEqual(30, $left);
    }

    public function test_the_board_is_offline_after_ten_seconds_of_silence(): void
    {
        $this->travel(11)->seconds();

        $this->asUser($this->admin)->getJson('/api/admin/stand/live')->assertOk()->assertJsonPath('data.board.online', false);
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['on' => true])->assertStatus(422);
        $this->assertFalse($this->board->fresh()->pump_target);
    }

    public function test_pump_endpoint_validates_and_turns_off_even_when_offline(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', [])->assertStatus(422);

        $this->pumpOn();
        $this->travel(11)->seconds();
        $this->asUser($this->admin)->postJson('/api/admin/stand/pump', ['on' => false])->assertOk()->assertJsonPath('data.pump.target', false);
    }

    public function test_calibrate_records_the_latest_raw_value(): void
    {
        $this->board->update(['calibrated_dry_at' => null, 'calibrated_wet_at' => null]);

        $this->report(['moisture_raw' => 2900])->assertCreated();
        $this->asUser($this->admin)->postJson('/api/admin/stand/calibrate', ['point' => 'dry'])->assertOk()
            ->assertJsonPath('data.calibration.raw_dry', 2900)->assertJsonPath('data.calibration.calibrated', false);

        $this->travel(2)->seconds();
        $this->report(['moisture_raw' => 1100])->assertCreated();
        $this->asUser($this->admin)->postJson('/api/admin/stand/calibrate', ['point' => 'wet'])->assertOk()
            ->assertJsonPath('data.calibration.raw_wet', 1100)->assertJsonPath('data.calibration.calibrated', true);

        $this->travel(2)->seconds();
        $this->report(['moisture_raw' => 2000])->assertCreated();
        $this->asUser($this->admin)->getJson('/api/admin/stand/live')->assertJsonPath('data.moisture.pct', 50);
    }

    public function test_calibrate_rejects_bad_input_and_a_dry_below_the_recorded_wet(): void
    {
        $this->asUser($this->admin)->postJson('/api/admin/stand/calibrate', ['point' => 'x'])->assertStatus(422);
        // no reading yet
        $this->asUser($this->admin)->postJson('/api/admin/stand/calibrate', ['point' => 'dry'])->assertStatus(422);

        $this->report(['moisture_raw' => 500])->assertCreated(); // below the recorded wet (1000)
        $this->asUser($this->admin)->postJson('/api/admin/stand/calibrate', ['point' => 'dry'])->assertStatus(422);
    }

    public function test_stand_endpoints_are_admin_only_and_company_scoped(): void
    {
        $worker = User::factory()->worker()->create(['company_id' => $this->company->id]);
        $this->asUser($worker)->getJson('/api/admin/stand/live')->assertForbidden();
        $this->asUser($worker)->postJson('/api/admin/stand/pump', ['on' => true])->assertForbidden();
        $this->asUser($worker)->postJson('/api/admin/stand/calibrate', ['point' => 'dry'])->assertForbidden();

        $other = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($other)->getJson('/api/admin/stand/live')->assertNotFound();
        $this->asUser($other)->postJson('/api/admin/stand/pump', ['on' => true])->assertNotFound();
        $this->asUser($other)->postJson('/api/admin/stand/calibrate', ['point' => 'dry'])->assertNotFound();
    }

    public function test_the_stand_is_left_out_of_the_park_list_and_the_overview(): void
    {
        $park = Park::factory()->create(['company_id' => $this->company->id, 'name_en' => 'Real Park One']);
        app(ReadingIngestor::class)->ingest($this->board->fresh(), ['moisture' => 2900, 'pump_on' => false]);

        $list = $this->asUser($this->admin)->getJson('/api/admin/parks')->assertOk();
        $this->assertSame([$park->id], collect($list->json('data'))->pluck('id')->all());

        // The overview shows the stand only in its own demo_stand block, never in the park counts.
        $this->asUser($this->admin)->getJson('/api/admin/overview')->assertOk()
            ->assertJsonPath('data.kpis.parks.total', 1)->assertJsonPath('data.demo_stand.park_id', $this->stand->id);
    }

    public function test_rotate_token_touches_only_bench_a(): void
    {
        $other = Node::factory()->create(['park_id' => $this->stand->id, 'name' => 'Bench B']);
        $otherPark = Node::factory()->create(['name' => 'Sim X']);
        $oldA = $this->board->createToken('device', ['device'])->accessToken->id;
        $otherToken = $other->createToken('device', ['device'])->accessToken->id;
        $parkToken = $otherPark->createToken('device', ['device'])->accessToken->id;

        $this->artisan('namaa:stand:rotate-token')->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldA]);
        $this->assertSame(1, $this->board->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $parkToken]);
    }

    public function test_rotate_token_dry_run_writes_nothing(): void
    {
        $id = $this->board->createToken('device', ['device'])->accessToken->id;

        $this->artisan('namaa:stand:rotate-token --dry-run')->expectsOutputToContain('Nothing changed')->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $id]);
        $this->assertSame(1, $this->board->tokens()->count());
    }

    public function test_rotate_token_ignores_a_bench_a_outside_the_stand_and_fails_on_none(): void
    {
        $this->board->delete();
        $outside = Node::factory()->create(['name' => 'Bench A']);
        $id = $outside->createToken('device', ['device'])->accessToken->id;

        $this->artisan('namaa:stand:rotate-token')->assertFailed();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $id]);
    }

    public function test_the_stand_is_excluded_from_impact_and_the_municipality_views(): void
    {
        $municipality = User::factory()->municipality()->create();

        $adminImpact = $this->asUser($this->admin)->getJson('/api/admin/impact')->assertOk()->json('parks') ?? [];
        $this->assertNotContains($this->stand->id, array_column($adminImpact, 'park_id'));
        $this->assertNotContains($this->stand->id, array_column($adminImpact, 'id'));

        $muniImpact = $this->asUser($municipality)->getJson('/api/municipality/impact')->assertOk()->json('parks') ?? [];
        $this->assertNotContains($this->stand->id, array_column($muniImpact, 'park_id'));
        $this->assertNotContains($this->stand->id, array_column($muniImpact, 'id'));

        $this->assertNotContains($this->stand->id, $this->asUser($municipality)->getJson('/api/municipality/parks')->assertOk()->json('data.*.id'));
    }
}
