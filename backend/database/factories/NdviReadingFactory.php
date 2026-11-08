<?php

namespace Database\Factories;

use App\Models\NdviReading;
use App\Models\Park;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NdviReading>
 */
class NdviReadingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'park_id' => Park::factory(),
            'date' => fake()->unique()->dateTimeBetween('-90 days', 'now')->format('Y-m-d'),
            'mean' => fake()->randomFloat(3, 0.15, 0.65),
            'pixels' => fake()->numberBetween(20, 400),
        ];
    }
}
