<?php

namespace App\Services;

use App\Enums\ParkStatus;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use Illuminate\Support\Facades\DB;

/**
 * Stores one reading from a sensor node and updates the cached state:
 * the node's last_* values and last_seen_at, the park's last_reading_at and status.
 *
 * Sprint 1: park status only (dry / over / ok from this reading). The rules
 * engine (alerts, offline, sensor_fault, ...) replaces updateStatus() in Sprint 2.
 */
class ReadingIngestor
{
    public function __construct(private readonly Calibration $calibration) {}

    /** @param array{moisture?: ?int, flow?: ?float, temperature?: ?float, humidity?: ?float, pump_on?: ?bool, water_level?: ?int} $data */
    public function ingest(Node $node, array $data): Reading
    {
        return DB::transaction(function () use ($node, $data) {
            $raw = isset($data['moisture']) ? (int) $data['moisture'] : null;

            $reading = Reading::create([
                'node_id' => $node->id,
                'park_id' => $node->park_id,
                'moisture_raw' => $raw,
                'moisture_pct' => $this->calibration->moisturePct($node, $raw),
                'flow' => $data['flow'] ?? null,
                'temperature' => $data['temperature'] ?? null,
                'humidity' => $data['humidity'] ?? null,
                'pump_on' => $data['pump_on'] ?? null,
                'water_level' => $data['water_level'] ?? null,
                'source' => $node->source,
            ]);

            $node->update([
                'last_seen_at' => $reading->created_at,
                'last_moisture_pct' => $reading->moisture_pct,
                'last_flow' => $reading->flow,
                'last_temperature' => $reading->temperature,
                'last_humidity' => $reading->humidity,
                'last_pump_on' => $reading->pump_on,
            ]);

            $park = $node->park()->lockForUpdate()->first();
            $park->last_reading_at = $reading->created_at;
            $this->updateStatus($park, $reading);
            $park->save();

            return $reading;
        });
    }

    private function updateStatus(Park $park, Reading $reading): void
    {
        if ($reading->moisture_pct === null) {
            return; // no moisture: keep the current status
        }

        $status = match (true) {
            $reading->moisture_pct < $park->moisture_min => ParkStatus::Dry,
            $reading->moisture_pct > $park->moisture_max => ParkStatus::Over,
            default => ParkStatus::Ok,
        };

        if ($park->status !== $status) {
            $park->status = $status;
            $park->status_changed_at = $reading->created_at;
        }
    }
}
