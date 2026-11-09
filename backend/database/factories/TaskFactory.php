<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Park;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /** Default: an open, unassigned manual task. */
    public function definition(): array
    {
        return [
            'park_id' => Park::factory(),
            // Same company as the park unless one is given.
            'company_id' => fn (array $attributes) => Park::find($attributes['park_id'])->company_id,
            'alert_id' => null,
            'assigned_to' => null,
            'created_by' => null,
            'title' => fake()->randomElement([
                'Check irrigation, soil is dry',
                'Inspect drip line for blockage',
                'Collect litter near the camera corner',
                'Restart the sensor node',
            ]),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Open,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::InProgress, 'started_at' => now()->subMinutes(20)]);
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Done,
            'started_at' => now()->subMinutes(40),
            'done_at' => now()->subMinutes(5),
            'done_note' => 'Cleared a blocked dripper line',
        ]);
    }
}
