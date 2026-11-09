<?php

namespace App\Services;

use App\Events\ReadingReceived;
use App\Models\Node;
use App\Models\Reading;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;

/**
 * Stores one reading from a sensor node, updates the cached state (the node's
 * last_* values and last_seen_at, the park's last_reading_at), runs the rules
 * engine and broadcasts reading.received. Used by the device route and the
 * simulator, so both behave the same.
 */
class ReadingIngestor
{
    public function __construct(
        private readonly Calibration $calibration,
        private readonly RulesEngine $rules,
    ) {}

    /** @param array{moisture?: ?int, flow?: ?float, temperature?: ?float, humidity?: ?float, pump_on?: ?bool, water_level?: ?int, battery_pct?: ?float} $data */
    public function ingest(Node $node, array $data): Reading
    {
        return DB::transaction(function () use ($node, $data) {
            $raw = isset($data['moisture']) ? (int) $data['moisture'] : null;

            $park = $node->park()->lockForUpdate()->first();

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
                'battery_pct' => isset($data['battery_pct']) ? round((float) $data['battery_pct'], 1) : null,
                'source' => $node->source,
            ]);

            $node->update([
                'last_seen_at' => $reading->created_at,
                'last_moisture_pct' => $reading->moisture_pct,
                'last_flow' => $reading->flow,
                'last_temperature' => $reading->temperature,
                'last_humidity' => $reading->humidity,
                'last_pump_on' => $reading->pump_on,
                'last_battery_pct' => $reading->battery_pct,
            ]);

            $park->last_reading_at = $reading->created_at;
            $park->save();
            $node->setRelation('park', $park);

            $this->rules->afterReading($reading, $node, $park);

            Realtime::send(new ReadingReceived($park->company_id, [
                'park_id' => $park->id,
                'node_id' => $node->id,
                'status' => $park->status->value,
                'reading' => [
                    'moisture_pct' => $reading->moisture_pct,
                    'flow' => $reading->flow,
                    'temperature' => $reading->temperature,
                    'humidity' => $reading->humidity,
                    'pump_on' => $reading->pump_on,
                    'battery_pct' => $reading->battery_pct,
                    'pump_target' => $node->has_pump && $node->pump_target,
                    'at' => $reading->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'source' => $reading->source->value,
                ],
            ], $park->activeWorkerIds()));

            return $reading;
        });
    }
}
