<?php

namespace Tests\Feature\Device;

use App\Enums\ParkStatus;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\User;
use App\Services\Calibration;
use App\Support\DeviceRejections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadingTest extends TestCase
{
    use RefreshDatabase;

    private function node(array $attributes = [], array $park = []): Node
    {
        $park = Park::factory()->create($park + ['moisture_min' => 25, 'moisture_max' => 70, 'status' => ParkStatus::Offline]);

        return Node::factory()->withPump()->create($attributes + [
            'park_id' => $park->id, 'dry_value' => 3200, 'wet_value' => 1300,
            'last_seen_at' => null, 'pump_target' => false,
        ]);
    }

    private function postReading(Node $node, array $body, ?string $token = null)
    {
        $token ??= $node->createToken('device', ['device'])->plainTextToken;

        return $this->withToken($token)->postJson('/api/device/readings', $body + ['node_id' => $node->id]);
    }

    public function test_a_valid_reading_is_stored_calibrated_and_cached(): void
    {
        $node = $this->node();

        // (3200 - 2250) / 1900 * 100 = 50.0
        $response = $this->postReading($node, [
            'moisture' => 2250, 'flow' => 1.25, 'temperature' => 31.5, 'humidity' => 22, 'pump_on' => true, 'water_level' => 1900,
        ]);

        $response->assertCreated()->assertJson(['pump' => false, 'interval_s' => 30, 'local_rule' => ['min' => 25, 'target' => 48, 'max_run_s' => 900]]);

        $reading = Reading::sole();
        $this->assertSame($node->park_id, $reading->park_id);
        $this->assertSame(2250, $reading->moisture_raw);
        $this->assertSame(50.0, $reading->moisture_pct);
        $this->assertSame(1.25, $reading->flow);
        $this->assertTrue($reading->pump_on);
        $this->assertSame('real', $reading->source->value);

        $node->refresh();
        $this->assertNotNull($node->last_seen_at);
        $this->assertSame(50.0, $node->last_moisture_pct);
        $this->assertSame(31.5, $node->last_temperature);
        $this->assertSame(22.0, $node->last_humidity);
        $this->assertTrue($node->last_pump_on);

        $park = $node->park->refresh();
        $this->assertSame(ParkStatus::Ok, $park->status);
        $this->assertNotNull($park->last_reading_at);
        $this->assertNotNull($park->status_changed_at);
    }

    public function test_the_source_is_copied_from_the_node(): void
    {
        $node = $this->node(['source' => 'simulated']);

        $this->postReading($node, ['moisture' => 2000])->assertCreated();

        $this->assertSame('simulated', Reading::sole()->source->value);
    }

    public function test_calibration_clamps_and_rounds(): void
    {
        $node = new Node(['dry_value' => 3200, 'wet_value' => 1300]);
        $calibration = new Calibration;

        $this->assertSame(0.0, $calibration->moisturePct($node, 4095));
        $this->assertSame(100.0, $calibration->moisturePct($node, 0));
        $this->assertSame(33.3, $calibration->moisturePct($node, 2567)); // 33.315...
        $this->assertNull($calibration->moisturePct($node, null));
    }

    public function test_park_status_comes_from_the_rules_engine_and_only_stamps_changes(): void
    {
        $node = $this->node();
        $token = $node->createToken('device', ['device'])->plainTextToken;
        $park = $node->park;

        $this->postReading($node, ['moisture' => 2250], $token); // 50 % -> ok (the node is online)
        $this->assertSame(ParkStatus::Ok, $park->refresh()->status);
        $changedAt = $park->status_changed_at;

        $this->travel(1)->minutes();
        $this->postReading($node, ['moisture' => 3000], $token); // 10.5 %: one dry reading is not enough
        $this->assertSame(ParkStatus::Ok, $park->refresh()->status);
        $this->assertEquals($changedAt, $park->status_changed_at);

        $this->postReading($node, ['moisture' => 2950], $token); // second dry reading: dry alert -> dry
        $this->assertSame(ParkStatus::Dry, $park->refresh()->status);
        $this->assertTrue($park->status_changed_at->gt($changedAt));
        $this->assertDatabaseHas('alerts', ['park_id' => $park->id, 'type' => 'dry', 'status' => 'open']);
    }

    public function test_reply_has_the_pump_target_and_the_demo_interval(): void
    {
        $node = $this->node(['pump_target' => true]);
        config(['namaa.demo_mode' => true]);

        $this->postReading($node, ['moisture' => 2000])->assertCreated()->assertJson(['pump' => true, 'interval_s' => 5]);
    }

    public function test_a_node_without_a_pump_always_gets_false(): void
    {
        $node = $this->node(['has_pump' => false, 'pump_target' => true]);

        $this->postReading($node, ['moisture' => 2000])->assertCreated()->assertJsonPath('pump', false);
    }

    public function test_node_id_must_match_the_token(): void
    {
        $node = $this->node();
        $other = $this->node();
        $token = $node->createToken('device', ['device'])->plainTextToken;

        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $other->id, 'moisture' => 2000])->assertForbidden();
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_a_user_token_is_rejected(): void
    {
        $node = $this->node();
        $token = User::factory()->admin()->create()->createToken('dashboard', ['user'])->plainTextToken;

        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $node->id, 'moisture' => 2000])->assertForbidden();
    }

    public function test_no_token_is_401(): void
    {
        $this->postJson('/api/device/readings', ['node_id' => 1, 'moisture' => 2000])->assertUnauthorized();
    }

    public function test_validation(): void
    {
        $node = $this->node();

        $this->postReading($node, ['moisture' => 5000, 'humidity' => 120, 'flow' => -1, 'pump_on' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['moisture', 'humidity', 'flow', 'pump_on']);
    }

    // ---- Sprint 4, B: board status, buffered readings, local rule, rejections, health ----

    public function test_the_reply_has_the_local_rule(): void
    {
        $node = $this->node(['dry_value' => 3100, 'wet_value' => 1200]);

        $this->postReading($node, ['moisture' => 2000])->assertCreated()
            ->assertJsonPath('local_rule.min', 25)
            ->assertJsonPath('local_rule.target', 48) // middle of 25..70
            ->assertJsonPath('local_rule.max_run_s', 900)
            ->assertJsonPath('local_rule.dry_value', 3100)
            ->assertJsonPath('local_rule.wet_value', 1200);
    }

    public function test_rssi_uptime_firmware_and_via_are_stored_on_the_node(): void
    {
        $node = $this->node();

        $this->postReading($node, ['moisture' => 2000, 'rssi' => -67, 'uptime_s' => 3600, 'fw_version' => '1.0.3-esp32s3', 'via' => 'usb'])->assertCreated();

        $node->refresh();
        $this->assertSame(-67, $node->rssi);
        $this->assertSame(3600, $node->uptime_s);
        $this->assertSame('1.0.3-esp32s3', $node->fw_version);
        $this->assertSame('usb', $node->via);

        // A later post without them keeps what is known.
        $this->postReading($node, ['moisture' => 2000])->assertCreated();
        $this->assertSame(-67, $node->refresh()->rssi);
    }

    public function test_the_new_fields_are_validated(): void
    {
        $node = $this->node();

        $this->postReading($node, ['rssi' => 5, 'uptime_s' => -1, 'fw_version' => str_repeat('x', 33), 'via' => 'bluetooth'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rssi', 'uptime_s', 'fw_version', 'via']);
    }

    public function test_a_buffered_old_reading_is_stored_at_its_time_without_alerts_or_status_change(): void
    {
        $node = $this->node(['last_seen_at' => now()->subMinute(), 'last_moisture_pct' => 50]);
        $node->park->update(['status' => ParkStatus::Ok]);

        // Soil was very dry 10 minutes ago, while the board was offline.
        $at = now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->postReading($node, ['moisture' => 3300, 'recorded_at' => $at, 'rssi' => -70])->assertCreated();

        $reading = Reading::sole();
        $this->assertSame($at, $reading->created_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(0.0, $reading->moisture_pct);
        $node->refresh();
        $this->assertSame(50.0, $node->last_moisture_pct, 'the node cache keeps the newer state');
        $this->assertTrue($node->last_seen_at->gt(now()->subMinutes(2)));
        $this->assertSame(-70, $node->rssi, 'the board status is current');
        $this->assertSame(0, Alert::count(), 'no alert from an old reading');
        $this->assertSame(ParkStatus::Ok, $node->park->refresh()->status);
    }

    public function test_a_buffered_reading_newer_than_the_latest_updates_the_node_and_runs_the_rules(): void
    {
        $node = $this->node(['last_seen_at' => now()->subMinutes(10), 'last_moisture_pct' => 50]);

        $this->postReading($node, ['moisture' => 3300, 'recorded_at' => now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:s\Z')])->assertCreated();

        $node->refresh();
        $this->assertSame(0.0, $node->last_moisture_pct);
        $this->assertTrue($node->last_seen_at->between(now()->subMinutes(3), now()->subMinute()));
    }

    public function test_recorded_at_in_the_future_or_older_than_24_hours_or_garbage_is_422(): void
    {
        $node = $this->node();

        foreach ([now()->addHour(), now()->subHours(25)] as $bad) {
            $this->postReading($node, ['moisture' => 2000, 'recorded_at' => $bad->utc()->format('Y-m-d\TH:i:s\Z')])
                ->assertStatus(422)->assertJsonValidationErrors(['recorded_at']);
        }
        $this->postReading($node, ['moisture' => 2000, 'recorded_at' => 'yesterday-ish'])->assertStatus(422)->assertJsonValidationErrors(['recorded_at']);
        $this->assertSame(0, Reading::count());

        $this->postReading($node, ['moisture' => 2000, 'recorded_at' => now()->subHours(23)->utc()->format('Y-m-d\TH:i:s\Z')])->assertCreated();
    }

    public function test_rejected_requests_are_kept_per_node_without_token_or_body(): void
    {
        $node = $this->node();
        $token = $node->createToken('device', ['device'])->plainTextToken;

        $this->postReading($node, ['moisture' => 9999], $token)->assertStatus(422);
        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $node->id + 99, 'moisture' => 2000])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken('999|wrongtokenvalue')->postJson('/api/device/readings', ['node_id' => $node->id])->assertUnauthorized();

        $log = DeviceRejections::forNode($node->id);
        $this->assertCount(2, $log);
        $this->assertSame(403, $log[0]['status'], 'newest first');
        $this->assertSame(422, $log[1]['status']);
        $this->assertStringContainsString('moisture', $log[1]['message']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $log[0]['at']);
        $encoded = json_encode([$log, DeviceRejections::last()]);
        $this->assertStringNotContainsString(explode('|', $token)[1], $encoded);
        $this->assertStringNotContainsString('9999', $encoded);
        $this->assertNull(DeviceRejections::last()['node_id'], 'the unknown token is the latest, kept by prefix');
        $this->assertSame('999|', DeviceRejections::last()['token_prefix']);
    }

    public function test_only_the_last_20_rejections_are_kept(): void
    {
        $node = $this->node();
        $token = $node->createToken('device', ['device'])->plainTextToken;

        for ($i = 0; $i < 22; $i++) {
            $this->postReading($node, ['moisture' => 9999], $token)->assertStatus(422);
        }

        $this->assertCount(20, DeviceRejections::forNode($node->id));
    }

    public function test_the_rate_limit_answer_is_recorded(): void
    {
        config(['namaa.device_readings_per_minute' => 1]);
        $node = $this->node();
        $token = $node->createToken('device', ['device'])->plainTextToken;

        $this->postReading($node, ['moisture' => 2000], $token)->assertCreated();
        $this->postReading($node, ['moisture' => 2000], $token)->assertStatus(429);

        $this->assertSame(429, DeviceRejections::forNode($node->id)[0]['status']);
    }

    public function test_admins_read_the_rejections_of_their_own_company_only(): void
    {
        $node = $this->node();
        $other = $this->node();
        $company = $node->park->company_id;
        $this->postReading($node, ['moisture' => 9999])->assertStatus(422);
        $this->postReading($other, ['moisture' => 9999])->assertStatus(422);
        $admin = User::factory()->admin()->create(['company_id' => $company]);

        $this->asUser($admin)->getJson('/api/admin/device-rejections')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.node_id', $node->id)
            ->assertJsonPath('data.0.requests.0.status', 422);
        $worker = User::factory()->worker()->create(['company_id' => $company]);
        $this->asUser($worker)->getJson('/api/admin/device-rejections')->assertForbidden();
    }

    public function test_the_public_health_endpoint(): void
    {
        $this->getJson('/api/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['status', 'time']);
    }
}
