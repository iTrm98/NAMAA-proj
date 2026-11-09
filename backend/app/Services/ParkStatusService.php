<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\ParkStatus;
use App\Events\ParkStatusChanged;
use App\Models\Park;
use App\Support\Namaa;
use App\Support\Realtime;

/**
 * Park status (decision table, "Park status"): offline > dry > over > ok.
 * offline = no sensor node of the park reported within the offline window;
 * dry / over = an open alert of that type. status_changed_at and the
 * park.status_changed broadcast only on a real change.
 */
class ParkStatusService
{
    public function recalculate(Park $park): bool
    {
        $status = $this->compute($park);

        if ($park->status === $status) {
            return false;
        }

        $previous = $park->status;
        $park->status = $status;
        $park->status_changed_at = now();
        $park->save();

        Realtime::send(new ParkStatusChanged($park->company_id, [
            'park_id' => $park->id,
            'status' => $status->value,
            'previous_status' => $previous?->value,
        ], $park->activeWorkerIds()));

        return true;
    }

    public function compute(Park $park): ParkStatus
    {
        $onlineAfter = now()->subSeconds(Namaa::timing('offline_seconds'));

        if (! $park->sensorNodes()->where('last_seen_at', '>=', $onlineAfter)->exists()) {
            return ParkStatus::Offline;
        }

        $open = $park->alerts()->open()
            ->whereIn('type', [AlertType::Dry->value, AlertType::HeatStress->value, AlertType::Over->value])
            ->pluck('type');

        return match (true) {
            // heat_stress is a dry alert that got worse: the map shows it as dry.
            $open->contains(AlertType::Dry), $open->contains(AlertType::HeatStress) => ParkStatus::Dry,
            $open->contains(AlertType::Over) => ParkStatus::Over,
            default => ParkStatus::Ok,
        };
    }
}
