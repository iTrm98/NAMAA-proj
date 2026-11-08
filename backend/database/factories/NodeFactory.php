<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\Park;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    /** Default: a sensor node without a pump (like Model B). */
    public function definition(): array
    {
        return [
            'park_id' => Park::factory(),
            'type' => NodeType::Sensor,
            'name' => 'Node '.fake()->unique()->numberBetween(1, 9999),
            'has_pump' => false,
            'pump_target' => false,
            'dry_value' => 3200,
            'wet_value' => 1300,
            'last_seen_at' => now(),
            'last_moisture_pct' => fake()->randomFloat(1, 20, 60),
            'last_flow' => null,
            'last_temperature' => fake()->randomFloat(1, 24, 42),
            'last_humidity' => fake()->randomFloat(1, 10, 50),
            'last_pump_on' => null,
            'source' => DataSource::Real,
        ];
    }

    /** A sensor node that switches a pump and has a flow sensor (like Model A). */
    public function withPump(): static
    {
        return $this->state(fn () => [
            'has_pump' => true,
            'last_flow' => 0.0,
            'last_pump_on' => false,
        ]);
    }

    public function camera(): static
    {
        return $this->state(fn () => [
            'type' => NodeType::Camera,
            'name' => 'Camera '.fake()->unique()->numberBetween(1, 999),
            'dry_value' => 3200,
            'wet_value' => 1300,
            'last_moisture_pct' => null,
            'last_flow' => null,
            'last_temperature' => null,
            'last_humidity' => null,
            'last_pump_on' => null,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subMinutes(30)]);
    }

    public function simulated(): static
    {
        return $this->state(fn () => ['source' => DataSource::Simulated]);
    }
}
