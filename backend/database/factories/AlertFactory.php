<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Park;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /** Default: an open dry-soil alert. */
    public function definition(): array
    {
        return [
            'park_id' => Park::factory(),
            'node_id' => null,
            'type' => AlertType::Dry,
            'severity' => AlertSeverity::Critical,
            // The base priority of the type (decision table).
            'priority' => fn (array $a) => ($a['type'] instanceof AlertType ? $a['type'] : AlertType::from($a['type']))->priority(),
            'status' => AlertStatus::Open,
            'task_ready_at' => now(),
            'data' => ['moisture_pct' => 18.4, 'moisture_min' => 25, 'readings' => 3],
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => AlertStatus::Resolved, 'resolved_at' => now()]);
    }
}
