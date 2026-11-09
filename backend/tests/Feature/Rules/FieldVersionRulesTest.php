<?php

namespace Tests\Feature\Rules;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\User;
use App\Services\ReadingIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sprint 2.5, phase C: simulated field-version alerts (battery, blockage). */
class FieldVersionRulesTest extends TestCase
{
    use RefreshDatabase;

    private Park $park;

    private Node $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->park = Park::factory()->simulated()->create(['moisture_min' => 25, 'moisture_max' => 70]);
        $this->node = Node::factory()->simulated()->withPump()->create(['park_id' => $this->park->id, 'expected_flow_l_min' => 2.0]);
    }

    /** @param array<string, mixed> $data */
    private function send(array $data, ?Node $node = null): void
    {
        $this->travel(5)->seconds();
        app(ReadingIngestor::class)->ingest(($node ?? $this->node)->fresh(), ['moisture' => 2250] + $data);
    }

    private function open(AlertType $type): ?Alert
    {
        return Alert::open()->where('type', $type)->first();
    }

    // ---- low battery ----

    public function test_low_battery_raises_after_two_readings_below_20_and_resolves_above_25(): void
    {
        $this->send(['battery_pct' => 21.0]);
        $this->send(['battery_pct' => 19.5]);
        $this->assertNull($this->open(AlertType::LowBattery));
        $this->send(['battery_pct' => 19.0]);

        $alert = $this->open(AlertType::LowBattery);
        $this->assertNotNull($alert);
        $this->assertSame(TaskPriority::Low, $alert->priority);
        $this->assertSame('devices', $alert->type->category()->value);
        $this->assertEquals(19.0, $alert->data['battery_pct']);

        $this->send(['battery_pct' => 18.0]);
        $this->assertSame(1, Alert::where('type', AlertType::LowBattery)->count(), 'no duplicate');

        $this->send(['battery_pct' => 24.0]);
        $this->send(['battery_pct' => 26.0]);
        $this->assertNotNull($this->open(AlertType::LowBattery), '24 % is not above 25 %');
        $this->send(['battery_pct' => 27.0]);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_nodes_without_a_battery_value_are_skipped(): void
    {
        $real = Node::factory()->create(['park_id' => Park::factory()->create()->id]);
        foreach (range(1, 4) as $i) {
            $this->send(['battery_pct' => null], $real);
        }

        $this->assertNull($this->open(AlertType::LowBattery));
        $this->assertNull($real->refresh()->last_battery_pct);
    }

    // ---- blockage ----

    public function test_blockage_raises_after_the_window_and_resolves_when_flow_is_back(): void
    {
        $this->node->update(['pump_target' => true]);
        $low = ['pump_on' => true, 'flow' => 0.7]; // 35 % of 2.0

        // Normal timings: 2 min at 30 s = 4 readings.
        foreach (range(1, 3) as $i) {
            $this->send($low);
        }
        $this->assertNull($this->open(AlertType::Blockage));
        $this->send($low);

        $alert = $this->open(AlertType::Blockage);
        $this->assertNotNull($alert);
        $this->assertSame(TaskPriority::High, $alert->priority);
        $this->assertSame('water', $alert->type->category()->value);
        $this->assertEquals(2.0, $alert->data['expected_flow']);
        $this->assertNull($this->open(AlertType::NoFlow), 'water still flows: not no_flow');

        $this->send($low);
        $this->assertSame(1, Alert::where('type', AlertType::Blockage)->count(), 'no duplicate');

        $this->send(['pump_on' => true, 'flow' => 1.3]); // 65 %: not enough
        $this->send(['pump_on' => true, 'flow' => 1.5]);
        $this->assertNotNull($this->open(AlertType::Blockage));
        $this->send(['pump_on' => true, 'flow' => 1.6]);
        $this->assertSame(AlertStatus::Resolved, $alert->refresh()->status);
    }

    public function test_blockage_uses_three_readings_in_demo_mode(): void
    {
        config(['namaa.demo_mode' => true]);
        $this->node->update(['pump_target' => true]);
        $this->send(['pump_on' => true, 'flow' => 0.6]);
        $this->send(['pump_on' => true, 'flow' => 0.6]);
        $this->assertNull($this->open(AlertType::Blockage));
        $this->send(['pump_on' => true, 'flow' => 0.6]);
        $this->assertNotNull($this->open(AlertType::Blockage));
    }

    public function test_blockage_is_skipped_without_an_expected_flow_or_with_the_pump_off(): void
    {
        $real = Node::factory()->withPump()->create(['park_id' => Park::factory()->create()->id, 'expected_flow_l_min' => null]);
        foreach (range(1, 5) as $i) {
            $this->send(['pump_on' => true, 'flow' => 0.5], $real);
            $this->send(['pump_on' => false, 'flow' => 0.0]); // simulated node, pump off
        }

        $this->assertSame(0, Alert::where('type', AlertType::Blockage)->count());
    }

    // ---- device endpoint ----

    public function test_the_device_endpoint_accepts_and_stores_an_optional_battery_value(): void
    {
        $token = $this->node->createToken('device', ['device'])->plainTextToken;

        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $this->node->id, 'moisture' => 2250, 'battery_pct' => 87.46])
            ->assertCreated();
        $this->assertSame(87.5, Reading::latest('id')->first()->battery_pct);
        $this->assertSame(87.5, $this->node->refresh()->last_battery_pct);

        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $this->node->id, 'moisture' => 2250])->assertCreated();
        $this->assertNull(Reading::latest('id')->first()->battery_pct, 'optional: the real boards never send it');

        $this->withToken($token)->postJson('/api/device/readings', ['node_id' => $this->node->id, 'battery_pct' => 101])
            ->assertUnprocessable()->assertJsonValidationErrors('battery_pct');
    }

    public function test_park_detail_shows_battery_and_expected_flow_only_where_data_exists(): void
    {
        $admin = User::factory()->admin()->create(['company_id' => $this->park->company_id]);
        $this->send(['battery_pct' => 64.0]);

        $this->asUser($admin)->getJson("/api/admin/parks/{$this->park->id}")->assertOk()
            ->assertJsonPath('data.latest.battery_pct', 64)
            ->assertJsonPath('data.nodes.0.battery_pct', 64)
            ->assertJsonPath('data.nodes.0.expected_flow_l_min', 2)
            ->assertJsonPath('data.nodes.0.source', 'simulated');
    }
}
