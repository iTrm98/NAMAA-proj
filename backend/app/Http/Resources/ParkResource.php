<?php

namespace App\Http\Resources;

use App\Enums\NodeType;
use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Node;
use App\Models\Park;
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
            'status' => $this->status->value,
            'status_changed_at' => $this->iso($this->status_changed_at),
            'last_reading_at' => $this->iso($this->last_reading_at),
            'source' => $this->source->value,
            'moisture_min' => $this->moisture_min,
            'moisture_max' => $this->moisture_max,
            'has_pump' => $pumpNode !== null,
            'pump_target' => $pumpNode?->pump_target,
            'auto_irrigation' => $this->auto_irrigation,
            'open_alerts_count' => (int) ($this->open_alerts_count ?? 0),
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
                'source' => $n->source->value,
                'has_pump' => $n->has_pump,
                'pump_target' => $n->pump_target,
                'battery_pct' => $n->last_battery_pct,
                'expected_flow_l_min' => $n->expected_flow_l_min,
            ])->all();

            $data['open_alerts'] = $this->openAlerts->map(fn ($a) => [
                'id' => $a->id,
                'node_id' => $a->node_id,
                'type' => $a->type->value,
                'category' => $a->type->category()->value,
                'priority' => $a->priority->value,
                'task_ready' => $a->isTaskReady(),
                'data' => $a->data,
                'created_at' => $this->iso($a->created_at),
            ])->all();

            $data['open_tasks'] = $this->relationLoaded('activeTasks')
                ? TaskResource::collection($this->activeTasks)->resolve()
                : [];

            $data['pump'] = $this->pump;
        }

        return $data;
    }
}
