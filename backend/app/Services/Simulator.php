<?php

namespace App\Services;

use App\Enums\DataSource;
use App\Enums\MonitoringMode;
use App\Enums\NodeType;
use App\Models\Node;
use App\Support\Namaa;
use Illuminate\Support\Carbon;

/**
 * One tick of the simulated parks: one reading per simulated sensor node,
 * stored through the same ReadingIngestor as the device route (so the rules
 * engine and the broadcasts run exactly as for a real board). Each node
 * continues from its soil moisture, in real time (per second elapsed since its
 * last reading, so DEMO_MODE's 5 s readings do not dry the soil faster):
 *
 *  - not pumping: the soil dries at simulator.drying_per_hour, faster in the
 *    afternoon heat (dryingPerHour), floor 8 %;
 *  - pump on (has_pump and pump_target): +pump_rise_per_minute, flow 1.5-2.5 L/min
 *    (a third of that while the `blockage` scenario runs);
 *  - stage mode (the node `reset` or `dry` puts on stage, Al Adel): fast drying
 *    and a fast pump (about 0.3 % and 3 % per 5 s), so the watering story fits in
 *    a minute; once the pump has watered it and is off again, normal physics;
 *  - the reported value is the soil value plus a little sensor noise.
 *  - Makkah in November: temperature about 27-38 °C (peak 14:00), humidity 20-55 %
 *    (inverse); about 42 °C while the `heat` scenario runs.
 *  - Field version (Sim 1-3): battery % about -1 % per 10 min, kept at or above
 *    the floor by the "solar panel"; held at 15 % by the `battery` scenario.
 *
 * Every limit is soft (with noise): a value pinned at a floor would repeat the
 * same raw value and look like a stuck sensor.
 */
class Simulator
{
    public function __construct(
        private readonly ReadingIngestor $ingestor,
        private readonly ScenarioService $scenario,
    ) {}

    /** Returns the number of readings stored. */
    public function tick(): int
    {
        $count = 0;

        $nodes = Node::query()
            ->where('source', DataSource::Simulated)
            ->where('type', NodeType::Sensor)
            ->whereNull('detached_at')
            ->whereHas('park', fn ($p) => $p->parks()->where('monitoring_mode', MonitoringMode::Simulated->value))
            ->with('park')
            ->orderBy('id')
            ->get();

        foreach ($nodes as $node) {
            if ($this->scenario->isOffline($node)) {
                continue;
            }

            $this->ingestor->ingest($node, $this->next($node));
            $count++;
        }

        return $count;
    }

    /** @return array{moisture: int, flow: float, temperature: float, humidity: float, pump_on: bool, water_level: null, battery_pct: ?float} */
    public function next(Node $node): array
    {
        $dt = $this->stepSeconds($node);
        $forced = $this->scenario->takeMoisture($node);
        $pct = $forced ?? $this->scenario->trueMoisture($node) ?? (float) ($node->last_moisture_pct ?? 50.0);
        $pumping = $node->has_pump && $node->pump_target;
        $staged = $this->scenario->isStaged($node);

        if ($pumping) {
            $blocked = $this->scenario->isBlocked($node);
            $ratio = $blocked ? (float) config('namaa.scenario.blockage_flow_ratio') : 1.0;
            $rise = $staged
                ? config('namaa.simulator.stage_pump_rise_per_second') * $dt
                : config('namaa.simulator.pump_rise_per_minute') * $dt / 60;
            $pct += $rise * $ratio;
            $expected = $node->expected_flow_l_min ?? (float) config('namaa.simulator.expected_flow_l_min');
            $flow = $blocked ? $expected * $ratio + $this->noise(0.05) : $this->between(1.5, 2.5);
            $this->scenario->markWatered($node);
        } else {
            if ($staged && $this->scenario->isWatered($node)) {
                // Watered and the pump is off again: the stage is over, real-time physics from here.
                $this->scenario->unstage($node);
                $staged = false;
            }
            $pct -= $staged
                ? config('namaa.simulator.stage_drying_per_second') * $dt
                : self::dryingPerHour(now()) * $dt / 3600;
            $pct = max($pct, 8.0); // very dry soil hardly loses more water
            $flow = $this->scenario->isLeaking($node) ? $this->between(0.6, 0.9) : 0.0;
        }

        // The soil value changes smoothly; only the reported value carries sensor noise.
        $pct = min(max($pct, 1.0), 97.0);
        $this->scenario->setTrueMoisture($node, $pct);
        $pct = $this->softClamp($pct + $this->noise((float) config('namaa.simulator.reading_noise_pct')), 1.0, 97.0);

        // Back to a raw ADC value with the node calibration, kept inside the valid range.
        $raw = (int) round($node->dry_value - $pct / 100 * ($node->dry_value - $node->wet_value));
        $raw = min(max($raw, 60), 4080);

        [$temperature, $humidity] = $this->weather(now());
        if ($this->scenario->isHot($node)) {
            $temperature = round((float) config('namaa.scenario.heat_temp_c') + $this->noise(0.3), 1);
            $humidity = round(12 + $this->noise(1.0), 1);
        }

        return [
            'moisture' => $raw,
            'flow' => round(max($flow, 0.0), 3),
            'temperature' => $temperature,
            'humidity' => $humidity,
            'pump_on' => $pumping,
            'water_level' => null,
            'battery_pct' => $this->nextBattery($node),
        ];
    }

    /**
     * Drying rate (moisture points per hour) at this time of day in Makkah:
     * the mean rate following the day's heat, highest at 14:00, lowest at 02:00.
     */
    public static function dryingPerHour(Carbon $at): float
    {
        $local = $at->copy()->setTimezone('Asia/Riyadh');
        $hour = $local->hour + $local->minute / 60;

        return (float) config('namaa.simulator.drying_per_hour')
            * (1 + (float) config('namaa.simulator.drying_daily_swing') * cos(($hour - 14) / 24 * 2 * M_PI));
    }

    /** Real seconds since the node's last reading (one tick before the first), capped at max_step_seconds. */
    private function stepSeconds(Node $node): float
    {
        if (! $node->last_seen_at) {
            return (float) Namaa::timing('simulator_tick_seconds');
        }
        $seconds = $node->last_seen_at->diffInSeconds(now(), true);

        return (float) min(max($seconds, 1), (int) config('namaa.simulator.max_step_seconds'));
    }

    /** Simulated field-version battery, or null for nodes without one. */
    private function nextBattery(Node $node): ?float
    {
        if (! $this->scenario->hasBattery($node)) {
            return null;
        }

        $forced = $this->scenario->forcedBattery($node);
        if ($forced !== null) {
            return round($forced + $this->noise(0.2), 1);
        }

        $pct = $this->scenario->battery($node) ?? $node->last_battery_pct ?? (float) config('namaa.scenario.reset_battery_pct');
        $pct -= config('namaa.simulator.battery_drop_per_second') * Namaa::timing('simulator_tick_seconds');

        $floor = (float) config('namaa.simulator.battery_floor_pct');
        if ($pct < $floor) {
            $pct = $floor + abs($this->noise(0.5)); // the solar panel tops it up
        }
        $pct = min($pct, 100.0);
        $this->scenario->setBattery($node, $pct);

        return round($pct, 1);
    }

    /** Keep a value inside [low, high] without pinning it to the limit (no repeated raw value). */
    private function softClamp(float $value, float $low, float $high): float
    {
        if ($value < $low) {
            return $low + abs($this->noise(0.4));
        }
        if ($value > $high) {
            return $high - abs($this->noise(0.4));
        }

        return $value;
    }

    /** @return array{float, float} temperature °C, humidity % for this time of day in Makkah */
    private function weather(Carbon $now): array
    {
        $local = $now->copy()->setTimezone('Asia/Riyadh');
        $hour = $local->hour + $local->minute / 60;
        $wave = cos(($hour - 14) / 24 * 2 * M_PI); // 1 at 14:00, -1 at 02:00

        return [
            round(32.5 + 5.5 * $wave + $this->noise(0.3), 1),
            round(min(max(37.5 - 17.5 * $wave + $this->noise(1.0), 5), 95), 1),
        ];
    }

    private function noise(float $amplitude): float
    {
        return (mt_rand() / mt_getrandmax() * 2 - 1) * $amplitude;
    }

    private function between(float $low, float $high): float
    {
        return $low + mt_rand() / mt_getrandmax() * ($high - $low);
    }
}
