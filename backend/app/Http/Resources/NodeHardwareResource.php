<?php

namespace App\Http\Resources;

use App\Enums\NodeType;
use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Node;
use App\Services\CameraPipeline;
use App\Services\OfflineChecker;
use App\Services\PumpService;
use App\Support\Namaa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A node on the hardware page (api.md, GET /api/admin/nodes). Needs `park` and `latestReading` loaded.
 *
 * @mixin Node
 */
class NodeHardwareResource extends JsonResource
{
    use FormatsTimes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isCamera = $this->type === NodeType::Camera;
        $offlineAfter = OfflineChecker::offlineSeconds($this->type);
        $seen = $this->last_seen_at;
        $reading = $isCamera ? null : $this->latestReading;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'source' => $this->source->value,
            'park' => [
                'id' => $this->park->id,
                'name_en' => $this->park->name_en,
                'name_ar' => $this->park->name_ar,
                'is_test' => (bool) $this->park->is_test,
            ],
            'has_pump' => $this->has_pump,
            'online' => $seen !== null && $seen->gt(now()->subSeconds($offlineAfter)),
            'status' => $this->isAwaitingInstall() ? 'awaiting_install' : ($seen !== null && $seen->gt(now()->subSeconds($offlineAfter)) ? 'online' : 'offline'),
            'last_seen_at' => $this->iso($seen),
            'seconds_since_seen' => $seen ? (int) $seen->diffInSeconds(now(), true) : null,
            'expected_interval_s' => Namaa::timing($isCamera ? 'camera_capture_seconds' : 'interval_s'),
            'offline_after_s' => $offlineAfter,
            'rssi' => $this->rssi,
            'uptime_s' => $this->uptime_s,
            'fw_version' => $this->fw_version,
            'via' => $this->via,
            'last_reading' => $reading ? [
                'at' => $this->iso($reading->created_at),
                'moisture_raw' => $reading->moisture_raw,
                'moisture_pct' => $reading->moisture_pct,
                'temperature' => $reading->temperature,
                'humidity' => $reading->humidity,
                'flow' => $reading->flow,
                'water_level' => $reading->water_level,
                'battery_pct' => $reading->battery_pct,
                'pump_on' => $reading->pump_on,
            ] : null,
            'calibration' => $isCamera ? null : [
                'dry_value' => $this->dry_value,
                'wet_value' => $this->wet_value,
                'latest_raw' => $reading?->moisture_raw,
                'latest_pct' => $reading?->moisture_pct,
            ],
            'pump' => $this->has_pump ? [
                'target' => $this->pump_target,
                'reported_on' => $this->last_pump_on,
                'test_until' => $this->iso(PumpService::relayTestUntil($this->id)),
            ] : null,
            'camera' => $isCamera ? [
                'capture_mode' => $this->capture_mode,
                'camera_url' => $this->camera_url,
                'effective_url' => $this->capture_mode === 'url' ? (CameraPipeline::urlFor($this->resource) ?: null) : null,
                'last_frame_at' => $this->iso($seen),
                'last_count' => $this->last_litter_count,
            ] : null,
        ];
    }
}
