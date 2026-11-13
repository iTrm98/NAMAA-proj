<?php

namespace App\Services;

use App\Events\ReadingReceived;
use App\Enums\DataSource;
use App\Enums\MonitoringMode;
use App\Enums\PumpReason;
use App\Models\Node;
use App\Models\Reading;
use App\Support\Realtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        private readonly PumpService $pump,
    ) {}

    /** @param array{moisture?: ?int, flow?: ?float, temperature?: ?float, humidity?: ?float, pump_on?: ?bool, boot?: ?bool, water_level?: ?int, battery_pct?: ?float, recorded_at?: ?string, rssi?: ?int, uptime_s?: ?int, fw_version?: ?string, via?: ?string} $data */
    public function ingest(Node $node, array $data): Reading
    {
        return DB::transaction(function () use ($node, $data) {
            $raw = isset($data['moisture']) ? (int) $data['moisture'] : null;

            $park = $node->park()->lockForUpdate()->first();

            $this->standFailSafe($node, $park, $data);

            // A detached node, or a park that is satellite_only, does not feed the park:
            // accepted (the board gets its normal reply) but nothing is stored or evaluated.
            if ($node->detached_at !== null || $park->monitoring_mode === MonitoringMode::SatelliteOnly) {
                $this->recordRejected($node, $node->detached_at !== null ? 'detached' : 'satellite_only');

                return new Reading(['node_id' => $node->id, 'park_id' => $node->park_id, 'source' => $node->source]);
            }

            // An accepted reading means the warning is over.
            if ($node->last_rejected_at !== null) {
                Node::whereKey($node->id)->update(['last_rejected_at' => null, 'last_rejected_reason' => null, 'rejected_count' => 0]);
                $node->forceFill(['last_rejected_at' => null, 'last_rejected_reason' => null, 'rejected_count' => 0])->syncOriginal();
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

    /**
     * Demo stand: a pump that was on must not come back by itself. A reading that follows a
     * silence longer than stand_checkin_seconds, or that says the board just booted (boot flag
     * or a smaller uptime), clears the pump target first; the reply then tells the board OFF.
     *
     * @param  array<string, mixed>  $data
     */
    private function standFailSafe(Node $node, \App\Models\Park $park, array $data): void
    {
        if (! $node->has_pump || ! $node->pump_target || $park->site_type !== \App\Enums\SiteType::DemoStand || ! empty($data['recorded_at'])) {
            return;
        }

        $rebooted = ! empty($data['boot'])
            || (isset($data['uptime_s']) && $node->uptime_s !== null && (int) $data['uptime_s'] < $node->uptime_s);

        if ($rebooted || $this->pump->silent($node)) {
            $node->setRelation('park', $park);
            $this->pump->set($node, false, PumpReason::AutoOffNoCheckin);
        }
    }

    /** Remember a refused post of a real board (last time, reason, count) so the Hardware page can warn. Simulated nodes are the simulator's own. */
    private function recordRejected(Node $node, string $reason): void
    {
        if ($node->source !== DataSource::Real || ! Schema::hasColumn('nodes', 'last_rejected_at')) {
            return;
        }
        Node::whereKey($node->id)->update([
            'last_rejected_at' => now(), 'last_rejected_reason' => $reason, 'rejected_count' => DB::raw('rejected_count + 1'),
        ]);
    }
}
