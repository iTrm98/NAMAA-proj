<?php

namespace App\Services;

use App\Enums\NodeType;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Models\Task;

/**
 * The gateway-era payload of GET /api/admin/stand/live (api.md, "Gateway, zones and the live stand").
 * In bench mode (no zone nodes) the old Bench A payload is also merged at the top level for older clients.
 */
class StandGatewayLive
{
    public function __construct(
        private readonly StandLive $live,
        private readonly GatewayState $gateway,
    ) {}

    public function hasZones(Park $stand): bool
    {
        return Node::hasZoneColumn() && Node::where('park_id', $stand->id)->whereNotNull('zone')->exists();
    }

    /** @return array<string, mixed> */
    public function envelope(Park $stand, ?Node $bench): array
    {
        $old = $bench ? $this->live->payload($stand, $bench) : null;
        $zones = $this->hasZones($stand);
        $out = [
            'park_id' => $stand->id,
            'source' => 'real',
            'mode' => $zones ? 'gateway' : 'bench',
            'gateway' => $zones ? $this->gatewayBlock() : null,
            'water_today_l' => 0.0,
            'zones' => [],
            'timeline' => [],
            'bench' => $old,
        ];

        if (! $zones) {
            return ($old ?? []) + $out;
        }

        $out['zones'] = $this->zones($stand);
        $out['water_today_l'] = round(array_sum(array_column($out['zones'], 'water_today_l')), 2);
        $out['timeline'] = $this->timeline($stand);

        return $out;
    }

    /** @return array<string, mixed> */
    private function gatewayBlock(): array
    {
        $s = $this->gateway->get();
        $at = $this->gateway->lastEventAt();

        return [
            'reachable' => $this->gateway->reachable(),
            'last_event_at' => $at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'age_s' => $at ? (int) $at->diffInSeconds(now(), true) : null,
            'auto' => $s['auto'] ?? null,
            'irrigating_zone' => $s['irrigating_zone'] ?? null,
            'pulses' => (int) ($s['pulses'] ?? 0),
            'last_cycle_ml' => $s['last_cycle_ml'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function zones(Park $stand): array
    {
        $iso = fn ($t) => $t?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $nodes = Node::where('park_id', $stand->id)->whereNotNull('zone')->orderBy('zone')->orderBy('id')->get();
        $sensors = $nodes->where('type', NodeType::Sensor);
        $cameras = $nodes->where('type', NodeType::Camera)->keyBy('zone');
        $state = $this->gateway->get()['zones'] ?? [];
        $offline = (int) config('gateway.offline_seconds');

        $alerts = Alert::open()->where('park_id', $stand->id)->with('activeTasks:id,alert_id')->get()
            ->filter(fn ($a) => ($a->data['origin'] ?? null) === 'gateway')->groupBy(fn ($a) => $a->data['zone'] ?? '');
        $trends = Reading::whereIn('node_id', $sensors->pluck('id'))
            ->where('created_at', '>=', now()->subSeconds(StandLive::TREND_SECONDS))->orderBy('created_at')->orderBy('id')
            ->get(['node_id', 'moisture_pct', 'created_at'])->groupBy('node_id');
        $detections = $cameras->mapWithKeys(fn ($c) => [$c->id => Detection::where('node_id', $c->id)
            ->orderByDesc('captured_at')->orderByDesc('id')->first()])->filter();

        $out = [];
        foreach ($sensors as $node) {
            $z = (string) $node->zone;
            $seen = $node->last_seen_at;
            $cam = $cameras->get($z);
            $det = $cam ? $detections->get($cam->id) : null;
            $zoneAlerts = $alerts->get($z, collect());
            $flags = GatewayIngestor::flagNames((int) ($state[$z]['flags'] ?? 0));

            $out[] = [
                'zone' => $z,
                'node_id' => $node->id,
                'name' => $node->name,
                'online' => $seen !== null && $seen->gt(now()->subSeconds($offline)),
                'age_s' => $seen ? (int) $seen->diffInSeconds(now(), true) : null,
                'last_seen_at' => $iso($seen),
                'soil_pct' => $node->last_moisture_pct,
                'temp_c' => $node->last_temperature,
                'humidity_pct' => $node->last_humidity,
                'flow_lpm' => $node->last_flow,
                'total_ml' => $state[$z]['total_ml'] ?? null,
                'water_today_l' => round($this->gateway->waterTodayMl($z) / 1000, 2),
                'pump_on' => (bool) $node->last_pump_on,
                'flags' => array_values(array_unique(array_merge($flags, $zoneAlerts->map(fn ($a) => $a->type->value)->all()))),
                'trend' => ($trends->get($node->id) ?? collect())->map(fn ($r) => ['at' => $iso($r->created_at), 'pct' => $r->moisture_pct])->values()->all(),
                'camera' => $cam ? [
                    'node_id' => $cam->id,
                    'online' => $cam->last_seen_at !== null && $cam->last_seen_at->gt(now()->subSeconds(OfflineChecker::offlineSeconds(NodeType::Camera))),
                    'has_url' => CameraPipeline::urlFor($cam) !== '',
                    'detection_id' => $det?->id,
                    'at' => $iso($det?->captured_at),
                    'count' => $det?->count,
                    'litter' => $det ? $det->count > 0 : null,
                    'image_url' => $det ? $det->imagePath().'?size=thumb' : null,
                ] : null,
                'alerts' => $zoneAlerts->map(fn ($a) => [
                    'id' => $a->id, 'type' => $a->type->value, 'severity' => $a->severity->value,
                    'raised_at' => $iso($a->created_at), 'task_id' => $a->activeTasks->first()?->id,
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /** Newest 20: alerts and tasks from the database, pump / auto / litter events from the gateway cache. @return list<array<string, mixed>> */
    private function timeline(Park $stand): array
    {
        $iso = fn ($t) => $t?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $size = (int) config('gateway.timeline_size');
        $events = $this->gateway->events();

        $alerts = Alert::where('park_id', $stand->id)->orderByDesc('id')->limit($size)->get();
        foreach ($alerts as $a) {
            $zone = $a->data['zone'] ?? null;
            $params = ['type' => $a->type->value, 'alert_id' => $a->id];
            $events[] = ['id' => "a{$a->id}r", 'at' => $iso($a->created_at), 'kind' => 'alert_raised', 'zone' => $zone, 'params' => $params];
            if ($a->resolved_at) {
                $events[] = ['id' => "a{$a->id}c", 'at' => $iso($a->resolved_at), 'kind' => 'alert_cleared', 'zone' => $zone, 'params' => $params];
            }
        }
        $zoneOf = $alerts->mapWithKeys(fn ($a) => [$a->id => $a->data['zone'] ?? null]);
        foreach (Task::where('park_id', $stand->id)->orderByDesc('id')->limit($size)->get() as $t) {
            $zone = $zoneOf[$t->alert_id] ?? null;
            $events[] = ['id' => "t{$t->id}c", 'at' => $iso($t->created_at), 'kind' => 'task_created', 'zone' => $zone, 'params' => ['task_id' => $t->id]];
            if ($t->verified && $t->verified_at) {
                $events[] = ['id' => "t{$t->id}v", 'at' => $iso($t->verified_at), 'kind' => 'task_verified', 'zone' => $zone, 'params' => ['task_id' => $t->id]];
            }
        }

        usort($events, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $size);
    }
}
