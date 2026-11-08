<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /** Default: a system event with no subject. Use for() / state() to attach one. */
    public function definition(): array
    {
        return [
            'company_id' => null,
            'user_id' => null,
            'action' => 'auth.login',
            'subject_type' => null,
            'subject_id' => null,
            'diff' => null,
            'ip' => fake()->ipv4(),
            'created_at' => now(),
        ];
    }
}
