<?php

namespace App\Http\Resources;

use App\Enums\NodeType;
use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Node;
use App\Models\Park;
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
            'status' => $this->status->value,
            'status_changed_at' => $this->iso($this->status_changed_at),
            'last_reading_at' => $this->iso($this->last_reading_at),
            'source' => $this->source->value,
            'moisture_min' => $this->moisture_min,
            'moisture_max' => $this->moisture_max,
            'has_pump' => $nodes->contains(fn (Node $n) => $n->type === NodeType::Sensor && $n->has_pump),
            'open_alerts_count' => (int) ($this->open_alerts_count ?? 0),
            'latest' => $sensor?->last_seen_at ? [
                'node_id' => $sensor->id,
                'moisture_pct' => $sensor->last_moisture_pct,
                'flow' => $sensor->last_flow,
                'temperature' => $sensor->last_temperature,
                'humidity' => $sensor->last_humidity,
                'pump_on' => $sensor->last_pump_on,
                'at' => $this->iso($sensor->last_seen_at),
                'source' => $sensor->source->value,
            ] : null,
        ];

        if ($this->relationLoaded('company')) {
            $data['company'] = ['id' => $this->company->id, 'name' => $this->company->name];
        }

        if ($this->detail) {
            $offlineAfter = now()->subMinutes(config('namaa.offline_minutes'));

            $data['nodes'] = $nodes->values()->map(fn (Node $n) => [
                'id' => $n->id,
                'name' => $n->name,
                'type' => $n->type->value,
                'last_seen_at' => $this->iso($n->last_seen_at),
                'online' => $n->last_seen_at !== null && $n->last_seen_at->gte($offlineAfter),
                'source' => $n->source->value,
                'has_pump' => $n->has_pump,
                'pump_target' => $n->pump_target,
            ])->all();

            $data['open_alerts'] = $this->openAlerts->map(fn ($a) => [
                'id' => $a->id,
                'node_id' => $a->node_id,
                'type' => $a->type->value,
                'severity' => $a->severity->value,
                'data' => $a->data,
                'created_at' => $this->iso($a->created_at),
            ])->all();
        }

        return $data;
    }
}
