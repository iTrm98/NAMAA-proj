<?php

namespace Database\Factories;

use App\Models\Detection;
use App\Models\Park;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Detection>
 */
class DetectionFactory extends Factory
{
    /** Default: a photo with 1-4 litter boxes (VGA 640x480). */
    public function definition(): array
    {
        $count = fake()->numberBetween(1, 4);
        $boxes = [];
        for ($i = 0; $i < $count; $i++) {
            $boxes[] = [
                fake()->numberBetween(0, 560),
                fake()->numberBetween(0, 400),
                fake()->numberBetween(20, 80),
                fake()->numberBetween(20, 80),
                fake()->randomFloat(2, 0.4, 0.95),
            ];
        }

        return [
            'park_id' => Park::factory(),
            'node_id' => null,
            'photo_path' => 'detections/'.fake()->uuid().'.jpg',
            'count' => $count,
            'boxes' => $boxes,
            'captured_at' => now(),
        ];
    }

    public function clean(): static
    {
        return $this->state(fn () => ['count' => 0, 'boxes' => []]);
    }
}
