<?php

namespace App\Services;

use App\Events\ReadingReceived;
use App\Enums\MonitoringMode;
use App\Models\Node;
use App\Models\Reading;
use App\Support\Realtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stores one reading from a sensor node, updates the cached state (the node's
 * last_* values and last_seen_at, the park's last_reading_at), runs the rules
 * engine and broadcasts reading.received. Used by the device route and the
 * simulator, so both behave the same.
 *
 * A buffered reading (`recorded_at`: the board was offline and sends it later) is
 * stored at that time. It updates the node's cached state, runs the rules and
 * broadcasts only when it is newer than the node's latest reading; an older one
 * is history only (no alert, no status change, no live update).
 */
class ReadingIngestor
{
    public function __construct(
        private readonly Calibration $calibration,
        private readonly RulesEngine $rules,
    ) {}

    /** @param array{moisture?: ?int, flow?: ?float, temperature?: ?float, humidity?: ?float, pump_on?: ?bool, water_level?: ?int, battery_pct?: ?float, recorded_at?: ?string, rssi?: ?int, uptime_s?: ?int, fw_version?: ?string, via?: ?string} $data */
    public function ingest(Node $node, array $data): Reading
    {
        return DB::transaction(function () use ($node, $data) {
            $raw = isset($data['moisture']) ? (int) $data['moisture'] : null;

            $park = $node->park()->lockForUpdate()->first();

            // A detached node, or a park that is satellite_only, does not feed the park:
            // accepted (the board gets its normal reply) but nothing is stored or evaluated.
            if ($node->detached_at !== null || $park->monitoring_mode === MonitoringMode::SatelliteOnly) {
                return new Reading(['node_id' => $node->id, 'park_id' => $node->park_id, 'source' => $node->source]);
            }

            $recordedAt = ! empty($data['recorded_at']) ? Carbon::parse($data['recorded_at'])->utc() : null;
            // Newer than the node's latest reading (or the first one): it is the current state.
            $latest = $node->last_seen_at !== null && $recordedAt !== null ? $recordedAt->gt($node->last_seen_at) : true;

            // What the board says about itself is current whenever it arrives.
            $device = array_filter([
                'rssi' => $data['rssi'] ?? null,
                'uptime_s' => $data['uptime_s'] ?? null,
                'fw_version' => $data['fw_version'] ?? null,
                'via' => $data['via'] ?? null,
            ], fn ($v) => $v !== null);

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
            ] + ($recordedAt !== null ? ['created_at' => $recordedAt] : []));

            if (! $latest) {
                $node->update($device);

                return $reading;
            }

            $node->update($device + [
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
                    'moisture_raw' => $reading->moisture_raw,
                    'water_level' => $reading->water_level,
                    'rssi' => $node->rssi,
                    'uptime_s' => $node->uptime_s,
                    'fw_version' => $node->fw_version,
                    'via' => $node->via,
                    'flow' => $reading->flow,
                    'temperature' => $reading->temperature,
                    'humidity' => $reading->humidity,
                    'pump_on' => $reading->pump_on,
                    'battery_pct' => $reading->battery_pct,
                    'pump_target' => $node->has_pump && $node->pump_target,
                    'at' => $reading->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'source' => $reading->source->value,
                ],
            ], $park->activeWorkerIds(), ! $park->is_test));

            return $reading;
        });
    }
}
