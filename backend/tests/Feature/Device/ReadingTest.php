<?php

namespace Tests\Feature\Device;

use App\Enums\ParkStatus;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\User;
use App\Services\Calibration;
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

        $response->assertCreated()->assertExactJson(['pump' => false, 'interval_s' => 30]);

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

        $this->postReading($node, ['moisture' => 2000])->assertCreated()->assertExactJson(['pump' => true, 'interval_s' => 5]);
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
}
