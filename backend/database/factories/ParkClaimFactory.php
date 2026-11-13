<?php

namespace Database\Factories;

use App\Models\Park;
use App\Models\ParkClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParkClaim>
 */
class ParkClaimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'park_id' => Park::factory(),
            'field' => 'total_area_m2',
            'value' => (string) fake()->numberBetween(5000, 90000),
            'source_name' => fake()->unique()->company(),
            'source_url' => null,
            'source_date' => null,
            'note' => null,
        ];
    }
}
