<?php

namespace Database\Factories;

use App\Enums\PumpReason;
use App\Models\Node;
use App\Models\PumpLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PumpLog>
 */
class PumpLogFactory extends Factory
{
    /** Default: switched by the system (user_id null). */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory()->withPump(),
            'user_id' => null,
            'target' => fake()->boolean(),
            'reason' => PumpReason::System,
            'created_at' => now(),
        ];
    }
}
