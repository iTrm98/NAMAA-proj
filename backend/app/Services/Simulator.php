<?php

namespace App\Services;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use App\Support\Namaa;
use Illuminate\Support\Carbon;

/**
 * One tick of the simulated parks: one reading per simulated sensor node,
 * stored through the same ReadingIngestor as the device route (so the rules
 * engine and the broadcasts run exactly as for a real board). Each node
 * continues its story from its latest reading:
 *
 *  - "drying" (Al Adel, Sim 1): about -0.3 % per tick, small noise, floor about 8 %.
 *    Once the pump has watered it, it stops drying and drifts slowly toward the
 *    middle of the range until the next `dry` or `reset` scenario (so a fixed
 *    park stays fixed long enough for the task verification).
 *  - "healthy" (the others): a slow walk inside the park's range.
 *  - pump on (has_pump and pump_target): about +3 % per tick, flow 1.5-2.5 L/min
 *    (a third of that while the `blockage` scenario runs).
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
        $park = $node->park;
        $story = config('namaa.simulator.stories')[$node->name] ?? 'healthy';
        $pct = $this->scenario->takeMoisture($node) ?? $node->last_moisture_pct ?? 50.0;
        $pumping = $node->has_pump && $node->pump_target;

        if ($pumping) {
            $blocked = $this->scenario->isBlocked($node);
            $ratio = $blocked ? (float) config('namaa.scenario.blockage_flow_ratio') : 1.0;
            $pct += (config('namaa.simulator.pump_rise_per_tick') + $this->noise(0.3)) * $ratio;
            $expected = $node->expected_flow_l_min ?? (float) config('namaa.simulator.expected_flow_l_min');
            $flow = $blocked ? $expected * $ratio + $this->noise(0.05) : $this->between(1.5, 2.5);
            $this->scenario->markWatered($node);
        } else {
            if ($story === 'drying' && ! $this->scenario->isWatered($node)) {
                $pct -= config('namaa.simulator.drying_per_tick') + $this->noise(0.1);
                if ($pct < 8.0) {
                    $pct = 8.0 + abs($this->noise(0.6)); // soft floor
                }
            } elseif ($story === 'drying') {
                // Watered: hold, drifting slowly toward the middle of the range (no jump).
                $pct += $this->noise(0.2) + (($park->moisture_min + $park->moisture_max) / 2 - $pct) * 0.03;
            } else {
                // Healthy: wander, pulled gently toward the upper middle of the range.
                $centre = ($park->moisture_min + $park->moisture_max) / 2 + 5;
                $pct += $this->noise(0.6) + ($centre - $pct) * 0.05;
                $pct = $this->softClamp($pct, $park->moisture_min + 5, $park->moisture_max - 5);
            }
            $flow = $this->scenario->isLeaking($node) ? $this->between(0.6, 0.9) : 0.0;
        }

        $pct = $this->softClamp($pct, 1.0, 97.0);

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
