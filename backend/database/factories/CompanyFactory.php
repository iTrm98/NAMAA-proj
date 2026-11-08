<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Green', 'Oasis', 'Al Haram', 'Makkah', 'Sidra']).' '
                .fake()->randomElement(['Landscaping', 'Gardens', 'Maintenance', 'Green Services']),
        ];
    }
}
