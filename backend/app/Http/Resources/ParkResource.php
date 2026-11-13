<?php

namespace App\Http\Resources;

use App\Enums\NodeType;
use App\Enums\PumpReason;
use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Node;
use App\Models\Park;
use App\Services\SatelliteBlock;
use App\Support\Namaa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A park for the map and lists (api.md, GET /api/admin/parks).
 * Needs `nodes` loaded and `open_alerts_count` (withCount). Detail fields
 * (`nodes`, `open_alerts`) are added when `$detail` is true; `company` when loaded.
 *
 * @mixin Park
 */
class ParkResource extends JsonResource
{
    use FormatsTimes;

    public bool $detail = false;

    /** Pump card data for the detail view (set by the controller). @var array<string, mixed>|null */
    public ?array $pump = null;

    /** @param array<string, mixed>|null $pump */
    public function withPump(?array $pump): static
    {
        $this->pump = $pump;

        return $this;
    }

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $nodes = $this->nodes->sortBy('id');
        $sensor = $nodes->first(fn (Node $n) => $n->type === NodeType::Sensor);
        $pumpNode = $nodes->first(fn (Node $n) => $n->type === NodeType::Sensor && $n->has_pump);

        $data = [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'district' => $this->district,
            'park_type' => $this->park_type->value,
            'area_m2' => $this->area_m2,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'polygon' => $this->polygon,
            'ndvi_recomputing' => $this->ndviRecomputing(),
            'status' => $this->status->value,
            'status_changed_at' => $this->iso($this->status_changed_at),
            'last_reading_at' => $this->iso($this->last_reading_at),
            'source' => $this->source->value,
            'is_test' => (bool) $this->is_test,
            'site_type' => $this->site_type->value,
            'monitoring_mode' => $this->monitoring_mode->value,
            'verification_status' => $this->verification_status->value,
            'geometry_source' => $this->geometry_source->value,
            'osm_id' => $this->osm_id,
            'name_unconfirmed' => (bool) ($this->name_unconfirmed ?? false),
            'geometry_updated_at' => $this->iso($this->geometry_updated_at),
            'archived_at' => $this->iso($this->archived_at),
            'boundary' => $this->polygon,
            'boundary_area_m2' => $this->area_m2,
            'green_zone' => $this->green_zone,
            'green_zone_area_m2' => $this->green_zone_area_m2,
            'demo_role' => $this->demo_role,
            'cover_photo' => $this->cover_photo_path ? [
                'url' => "/api/admin/parks/{$this->id}/photo",
                'at' => $this->iso($this->cover_photo_at),
            ] : null,
            'moisture_min' => $this->moisture_min,
            'moisture_max' => $this->moisture_max,
            'has_pump' => $pumpNode !== null,
            'pump_target' => $pumpNode?->pump_target,
            'auto_irrigation' => $this->auto_irrigation,
            // Automatic irrigation is watering right now: the setting is on, the pump is on and the
            // newest switch was made by the rule (not by a person). "Watering automatically".
            'auto_irrigation_active' => (bool) ($this->auto_irrigation && $pumpNode?->pump_target
                && ($pumpNode->relationLoaded('latestPumpLog') ? $pumpNode->latestPumpLog : $pumpNode->latestPumpLog()->first())?->reason === PumpReason::Rule),
            'open_alerts_count' => (int) ($this->open_alerts_count ?? 0),
            // Parks with a camera node: its last frame (map popup). Null without a camera.
            'camera' => ($camera = $nodes->first(fn (Node $n) => $n->type === NodeType::Camera)) ? [
                'node_id' => $camera->id,
                'name' => $camera->name,
                'source' => $camera->source->value,
                'capture_mode' => $camera->capture_mode,
                'last_count' => $camera->last_litter_count,
                'last_frame_at' => $this->iso($camera->last_seen_at),
            ] : null,
            // Newest Sentinel-2 value (map NDVI layer); null before the first fetch.
            'ndvi' => SatelliteBlock::legacy($this->resource),
            // The satellite numbers with their trust level (api.md, Sprint 6.2 Phases B and C); null before the first fetch.
            'satellite' => SatelliteBlock::for($this->resource),
            'latest' => $sensor?->last_seen_at ? [
                'node_id' => $sensor->id,
                'moisture_pct' => $sensor->last_moisture_pct,
                'flow' => $sensor->last_flow,
                'temperature' => $sensor->last_temperature,
                'humidity' => $sensor->last_humidity,
                'pump_on' => $sensor->last_pump_on,
                'battery_pct' => $sensor->last_battery_pct,
                'at' => $this->iso($sensor->last_seen_at),
                'source' => $sensor->source->value,
            ] : null,
        ];

        if ($this->relationLoaded('company')) {
            $data['company'] = ['id' => $this->company->id, 'name' => $this->company->name];
        }

        if ($this->detail) {
            $offlineAfter = now()->subSeconds(Namaa::timing('offline_seconds'));

            $data['nodes'] = $nodes->values()->map(fn (Node $n) => [
                'id' => $n->id,
                'name' => $n->name,
                'type' => $n->type->value,
                'last_seen_at' => $this->iso($n->last_seen_at),
                'online' => $n->last_seen_at !== null && $n->last_seen_at->gte($offlineAfter),
                'status' => $n->isAwaitingInstall() ? 'awaiting_install' : ($n->last_seen_at !== null && $n->last_seen_at->gte($offlineAfter) ? 'online' : 'offline'),
                'source' => $n->source->value,
                'has_pump' => $n->has_pump,
                'pump_target' => $n->pump_target,
                'battery_pct' => $n->last_battery_pct,
                'rssi' => $n->rssi,
                'uptime_s' => $n->uptime_s,
                'fw_version' => $n->fw_version,
                'via' => $n->via,
                'expected_flow_l_min' => $n->expected_flow_l_min,
            ])->all();

            $data['open_alerts'] = $this->openAlerts->map(fn ($a) => [
                'id' => $a->id,
                'node_id' => $a->node_id,
                'type' => $a->type->value,
                'category' => $a->type->category()->value,
                'priority' => $a->priority->value,
                'task_ready' => $a->isTaskReady(),
                'detection_id' => $a->detection_id,
                'detection' => $a->relationLoaded('detection') && $a->detection
                    ? (new DetectionResource($a->detection))->resolve()
                    : null,
                'data' => $a->data,
                'created_at' => $this->iso($a->created_at),
            ])->all();

            $data['open_tasks'] = $this->relationLoaded('activeTasks')
                ? TaskResource::collection($this->activeTasks)->resolve()
                : [];

            $data['pump'] = $this->pump;

            $data['claims'] = ($this->relationLoaded('claims') ? $this->claims : $this->claims()->orderBy('id')->get())
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'field' => $c->field,
                    'value' => $c->value,
                    'source_name' => $c->source_name,
                    'source_url' => $c->source_url,
                    'source_date' => $c->source_date?->toDateString(),
                    'note' => $c->note,
                ])->values()->all();

            // Where the outline and the area come from (Sprint 3.1, D1).
            $source = fn (string $field) => [
                'type' => $this->{"{$field}_source"},
                'reference' => $this->{"{$field}_reference"},
                'by' => $this->relationLoaded("{$field}By") && $this->{"{$field}By"}
                    ? ['id' => $this->{"{$field}By"}->id, 'name' => $this->{"{$field}By"}->name] : null,
                'at' => $this->iso($this->{"{$field}_at"}),
            ];
            $data['sources'] = ['outline' => $source('outline'), 'area' => $source('area')];
        }

        return $data;
    }
}
