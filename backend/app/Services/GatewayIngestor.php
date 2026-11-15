<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\NodeType;
use App\Enums\SiteType;
use App\Enums\TaskStatus;
use App\Events\PumpChanged;
use App\Events\ReadingReceived;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Support\Realtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Applies what the Gateway pushes (POST /api/namaa/ingest, run by ProcessGatewayEvent).
 * Zone nodes are the Gateway's: readings are written straight to `readings` (flow in L/min),
 * the RulesEngine never runs for them (the Gateway decides the pump and raises the alerts),
 * and alerts follow the Gateway's raised / cleared events and every telemetry `active` list.
 */
class GatewayIngestor
{
    /** Bit -> plain status (the Gateway's `flags`). */
    public const FLAGS = [1 => 'tank_low', 2 => 'no_flow', 4 => 'leak', 8 => 'soil_fault', 16 => 'dht_fault', 32 => 'gateway_lost', 64 => 'pump_locked'];

    /** Gateway alert type -> our type; anything else is ignored. */
    private const TYPES = [
        'dry' => AlertType::Dry, 'leak' => AlertType::Leak, 'no_flow' => AlertType::NoFlow, 'litter' => AlertType::Litter,
        'offline' => AlertType::Offline, 'tank_low' => AlertType::TankLow, 'heat' => AlertType::Heat,
    ];

    public function __construct(
        private readonly AlertService $alerts,
        private readonly GatewayState $state,
        private readonly ActivityLogger $logger,
        private readonly ParkStatusService $status,
    ) {}

    /** The stand park that has zone nodes (any company: the Gateway is one device). */
    public static function park(): ?Park
    {
        if (! Node::hasZoneColumn()) {
            return null;
        }

        return Park::where('site_type', SiteType::DemoStand->value)->whereNull('archived_at')
            ->whereHas('nodes', fn ($n) => $n->whereNotNull('zone'))->orderBy('id')->first();
    }

    /** @return list<string> plain statuses of a flags bit mask */
    public static function flagNames(int $flags): array
    {
        $names = [];
        foreach (self::FLAGS as $bit => $name) {
            if ($flags & $bit) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $data */
    public function handle(string $event, array $data): void
    {
        $park = self::park();
        if ($park === null) {
            Log::warning('Gateway event ignored: no stand with zone nodes.', ['event' => $event]);

            return;
        }

        $event === 'alert' ? $this->alert($park, $data) : $this->telemetry($park, $data);
    }

    /** @param array<string, mixed> $data */
    public function telemetry(Park $park, array $data): void
    {
        $now = now();
        $prev = $this->state->get();
        $auto = self::bool(self::pick($data, ['auto', 'autoMode', 'auto_mode']));
        $irrigating = self::pick($data, ['irrigatingZone', 'irrigating_zone']);
        $cycle = self::pick($data, ['lastCycleMl', 'last_cycle_ml']);

        $zones = [];
        foreach (self::zonesOf($data) as $letter => $z) {
            $node = Node::where('park_id', $park->id)->where('zone', $letter)->where('type', NodeType::Sensor->value)->first();
            if ($node === null) {
                continue;
            }
            $zones[$letter] = $this->zone($park, $node, $letter, $z, $now);
        }

        if (array_key_exists('auto', $prev) && $auto !== null && $prev['auto'] !== $auto) {
            $this->state->push('auto_changed', null, ['on' => $auto]);
            $this->logger->log('gateway.auto_changed', $park, ['after' => ['auto' => $auto]], companyId: $park->company_id);
        }

        $this->state->merge([
            'last_event_at' => $now->toIso8601String(),
            'auto' => $auto ?? ($prev['auto'] ?? null),
            'irrigating_zone' => $irrigating ?: null,
            'pulses' => (int) (self::pick($data, ['pulses']) ?? ($prev['pulses'] ?? 0)),
            'last_cycle_ml' => $cycle !== null ? (int) $cycle : ($prev['last_cycle_ml'] ?? null),
            'zones' => $zones + ($prev['zones'] ?? []),
        ]);

        $active = self::pick($data, ['active']);
        if (is_array($active)) {
            $this->reconcile($park, $active);
        }
    }

    /**
     * @param  array<string, mixed>  $z
     * @return array<string, mixed> what the live payload needs about the zone
     */
    private function zone(Park $park, Node $node, string $letter, array $z, Carbon $now): array
    {
        $online = self::bool(self::pick($z, ['online'])) ?? true;
        $ageS = (int) (self::pick($z, ['ageS', 'age_s']) ?? 0);
        $soil = self::num(self::pick($z, ['soilPct', 'soil_pct', 'soil']));
        $temp = self::num(self::pick($z, ['tempC', 'temp_c', 'temperature']));
        $hum = self::num(self::pick($z, ['humidity', 'humidityPct', 'humidity_pct']));
        $flowMl = self::num(self::pick($z, ['flowMlMin', 'flow_ml_min']));
        $flowL = $flowMl !== null ? round($flowMl / 1000, 3) : null;
        $totalMl = self::pick($z, ['totalMl', 'total_ml']);
        $pumpOn = self::bool(self::pick($z, ['pumpOn', 'pump_on']));
        $flags = (int) (self::pick($z, ['flags']) ?? 0);
        $seen = $now->copy()->subSeconds(max(0, $ageS));

        if ($online) {
            $before = $node->last_pump_on;
            $fields = [
                'last_seen_at' => $seen,
                'last_moisture_pct' => $soil, 'last_temperature' => $temp, 'last_humidity' => $hum,
                'last_flow' => $flowL, 'last_pump_on' => $pumpOn,
            ];
            // The Gateway decides the pump: mirror its state, never command it from a reading.
            if ($node->has_pump && $pumpOn !== null) {
                $fields['pump_target'] = $pumpOn;
            }
            $node->forceFill($fields)->save();

            if ($pumpOn !== null && $before !== null && $pumpOn !== $before) {
                $this->pumpChanged($park, $node, $letter, $pumpOn);
            }

            if (Cache::add('namaa:gateway:reading:'.$letter, 1, (int) config('gateway.reading_every_seconds'))) {
                $reading = Reading::create([
                    'node_id' => $node->id, 'park_id' => $park->id,
                    'moisture_raw' => null, 'moisture_pct' => $soil, 'flow' => $flowL,
                    'temperature' => $temp, 'humidity' => $hum, 'pump_on' => $pumpOn,
                    'source' => $node->source,
                ]);
                $park->forceFill(['last_reading_at' => $reading->created_at])->save();
                $this->broadcast($park, $node, $reading);
            }
        }

        if ($totalMl !== null) {
            $this->state->addTotal($letter, (int) $totalMl);
        }

        return [
            'online' => $online, 'age_s' => $ageS, 'flags' => $flags,
            'total_ml' => $totalMl !== null ? (int) $totalMl : null,
        ];
    }

    private function pumpChanged(Park $park, Node $node, string $zone, bool $on): void
    {
        $this->state->push($on ? 'pump_on' : 'pump_off', $zone);
        $this->logger->log('pump.switched', $node, [
            'before' => ['pump_target' => ! $on], 'after' => ['pump_target' => $on, 'reason' => 'gateway'],
        ], companyId: $park->company_id);
        Realtime::send(new PumpChanged($park->company_id, [
            'park_id' => $park->id, 'node_id' => $node->id, 'pump_target' => $on, 'reason' => 'gateway',
            'by' => null, 'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $park->activeWorkerIds()));
    }

    private function broadcast(Park $park, Node $node, Reading $reading): void
    {
        Realtime::send(new ReadingReceived($park->company_id, [
            'park_id' => $park->id, 'node_id' => $node->id, 'status' => $park->status->value, 'zone' => $node->zone,
            'reading' => [
                'moisture_pct' => $reading->moisture_pct, 'moisture_raw' => null, 'water_level' => null,
                'flow' => $reading->flow, 'temperature' => $reading->temperature, 'humidity' => $reading->humidity,
                'pump_on' => $reading->pump_on, 'battery_pct' => null, 'pump_target' => (bool) $node->pump_target,
                'at' => $reading->created_at->utc()->format('Y-m-d\TH:i:s\Z'), 'source' => $reading->source->value,
            ],
        ], $park->activeWorkerIds(), ! $park->is_test));
    }

    // ---- alerts ----

    /** @param array<string, mixed> $data */
    public function alert(Park $park, array $data): void
    {
        $zone = strtoupper((string) self::pick($data, ['zone']));
        $key = strtolower((string) self::pick($data, ['type']));
        $type = self::TYPES[$key] ?? null;
        if ($type === null || ! in_array($zone, ['A', 'B'], true)) {
            Log::info('Gateway alert ignored.', ['type' => $key, 'zone' => $zone]);

            return;
        }

        $extra = array_diff_key($data, array_flip(['zone', 'type', 'state']));
        $open = $this->openGateway($park);

        if (strtolower((string) self::pick($data, ['state'])) === 'cleared') {
            $alert = $open->first(fn (Alert $a) => $a->type === $type && ($a->data['zone'] ?? null) === $zone);
            if ($alert) {
                $this->clear($alert);
            }
        } else {
            $this->raise($park, $zone, $type, $extra, $open);
        }
    }

    /** @param list<mixed> $active */
    private function reconcile(Park $park, array $active): void
    {
        $wanted = [];
        foreach ($active as $row) {
            if (! is_array($row)) {
                continue;
            }
            $zone = strtoupper((string) self::pick($row, ['zone']));
            $type = self::TYPES[strtolower((string) self::pick($row, ['type']))] ?? null;
            if ($type !== null && in_array($zone, ['A', 'B'], true)) {
                $wanted["$zone|{$type->value}"] = [$zone, $type, array_diff_key($row, array_flip(['zone', 'type', 'state']))];
            }
        }

        $open = $this->openGateway($park);
        foreach ($wanted as [$zone, $type, $extra]) {
            $this->raise($park, $zone, $type, $extra, $open);
        }
        foreach ($open as $alert) {
            if (! isset($wanted[($alert->data['zone'] ?? '').'|'.$alert->type->value])) {
                $this->clear($alert);
            }
        }
    }

    /** @return Collection<int, Alert> open alerts the Gateway raised */
    private function openGateway(Park $park): Collection
    {
        return Alert::open()->where('park_id', $park->id)->with('park')->get()
            ->filter(fn (Alert $a) => ($a->data['origin'] ?? null) === 'gateway')->values();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  Collection<int, Alert>  $open
     */
    private function raise(Park $park, string $zone, AlertType $type, array $extra, Collection $open): void
    {
        $existing = $open->first(fn (Alert $a) => $a->type === $type && ($a->data['zone'] ?? null) === $zone);
        if ($existing) {
            if (isset($existing->data['gateway_cleared_at'])) {
                $data = $existing->data;
                unset($data['gateway_cleared_at']);
                $this->alerts->touch($existing, $data);
                $this->logger->log('alert.gateway_reraised', $existing, ['after' => ['zone' => $zone]], companyId: $park->company_id);
            }

            return;
        }

        $sensor = Node::where('park_id', $park->id)->where('zone', $zone)->where('type', NodeType::Sensor->value)->first();
        $node = $sensor;
        $attributes = [];
        if ($type->isLitter()) {
            $camera = Node::where('park_id', $park->id)->where('zone', $zone)->where('type', NodeType::Camera->value)->first();
            $node = $camera ?? $sensor;
            $detection = $camera ? Detection::where('node_id', $camera->id)->latest('captured_at')->latest('id')->first() : null;
            if ($detection) {
                $attributes['detection_id'] = $detection->id;
            }
        }

        $alert = $this->alerts->raise($park, $node, $type, $type->severity(), ['origin' => 'gateway', 'zone' => $zone] + $extra, attributes: $attributes);
        $alert->setRelation('park', $park);
        $open->push($alert);
    }

    /** No active task: resolve. An active task keeps it open until the task is verified; the time is stamped. */
    private function clear(Alert $alert): void
    {
        if ($alert->status !== AlertStatus::Open) {
            return;
        }
        // A task still open, or done and waiting for its verification, keeps the alert open.
        $pending = $alert->tasks()->where(fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, TaskStatus::active()))
            ->orWhere(fn ($q) => $q->where('status', TaskStatus::Done->value)->whereNull('verified')))->exists();
        if ($pending) {
            if (! isset($alert->data['gateway_cleared_at'])) {
                $this->alerts->touch($alert, ($alert->data ?? []) + ['gateway_cleared_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')]);
                $this->logger->log('alert.gateway_cleared', $alert, ['after' => ['zone' => $alert->data['zone'] ?? null]], companyId: $alert->park->company_id);
            }

            return;
        }

        $this->alerts->resolve($alert, 'gateway_cleared');
        $this->status->recalculate($alert->park);
    }

    // ---- helpers ----

    /**
     * Zones of a telemetry body, keyed by letter: a map {A: {...}} or a list of {zone: "A", ...}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    public static function zonesOf(array $data): array
    {
        $out = [];
        foreach ((array) (self::pick($data, ['zones']) ?? []) as $k => $z) {
            if (! is_array($z)) {
                continue;
            }
            $letter = strtoupper((string) (is_string($k) ? $k : self::pick($z, ['zone'])));
            if (in_array($letter, ['A', 'B'], true)) {
                $out[$letter] = $z;
            }
        }

        return $out;
    }

    /** First present key (camelCase or snake_case spellings). @param list<string> $keys */
    public static function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                return $data[$k];
            }
        }

        return null;
    }

    private static function bool(mixed $v): ?bool
    {
        return $v === null ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
