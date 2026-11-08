<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Models\Node;
use App\Models\Reading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reading>
 */
class ReadingFactory extends Factory
{
    public function definition(): array
    {
        $raw = fake()->numberBetween(1600, 3000);

        return [
            'node_id' => Node::factory(),
            // Copy the node's park unless one is given.
            'park_id' => fn (array $attributes) => Node::find($attributes['node_id'])->park_id,
            'moisture_raw' => $raw,
            // Same formula as the server: (dry - raw) / (dry - wet) * 100 with the default calibration.
            'moisture_pct' => round(max(0, min(100, (3200 - $raw) / (3200 - 1300) * 100)), 1),
            'flow' => null,
            'temperature' => fake()->randomFloat(1, 24, 42),
            'humidity' => fake()->randomFloat(1, 10, 50),
            'pump_on' => null,
            'water_level' => null,
            'source' => DataSource::Real,
            'created_at' => now(),
        ];
    }

    /** A reading from a pump node (flow and pump state set). */
    public function pumping(float $flow = 0.8): static
    {
        return $this->state(fn () => ['pump_on' => true, 'flow' => $flow]);
    }

    public function simulated(): static
    {
        return $this->state(fn () => ['source' => DataSource::Simulated]);
    }
}
