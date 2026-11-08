<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Generates 7 days of 10-minute readings for one simulated sensor node, with a
 * Makkah November pattern, and the incidents the demo needs.
 *
 * Model (all values are demo assumptions, not measurements):
 * - Temperature 24-36 C, peak at 14:00 Makkah time; humidity 20-55 % RH, inverse.
 * - Scheduled irrigation at 06:00 and 18:00 Makkah time: pump on for 15 minutes
 *   (two 10-minute readings), flow 1.5-3 L/min, moisture rises about 15 points.
 * - Between irrigations moisture decays towards 10 %, faster when it is hot.
 *
 * Scenarios:
 * - healthy:    normal schedule.
 * - drying_out: irrigation stops 2 days before the end; moisture falls below 25
 *               and stays there. Reports when the dry rule would have fired.
 * - leak:       a leak 3 days ago (flow with the pump off), fixed after 42 minutes.
 * - dry_fixed:  missed irrigation about 5 days ago; dry alert, fixed by a worker
 *               after 65 minutes (manual watering during the task).
 *
 * Noise is deterministic per park key, so every seed run looks the same
 * (only the time window moves with "now").
 */
class ReadingSimulator
{
    public const STEP_MIN = 10;

    public const DAYS = 7;

    private const TZ = 'Asia/Riyadh'; // Makkah, UTC+3, no daylight saving

    private const FLOOR = 10.0; // moisture the soil dries towards

    private Randomizer $random;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var array<string, mixed> */
    private array $events = [];

    public function __construct(
        private readonly string $key,
        private readonly string $scenario,
        private readonly int $nodeId,
        private readonly int $parkId,
        private readonly int $dryValue,
        private readonly int $wetValue,
        private readonly int $moistureMin,
        private readonly CarbonImmutable $end,
    ) {
        $this->random = new Randomizer(new Mt19937(crc32($key)));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, events: array<string, mixed>}
     */
    public function run(): array
    {
        $steps = self::DAYS * 24 * 60 / self::STEP_MIN;
        $start = $this->end->subMinutes(($steps - 1) * self::STEP_MIN);
        $endLocal = $this->end->setTimezone(self::TZ);

        // Fixed incident times (UTC), derived from Makkah local dates.
        $irrigationStopsAt = $this->end->subDays(2);
        $leakStart = $endLocal->subDays(3)->setTime(10, 0)->utc();
        $leakAlertAt = $leakStart->addMinutes(self::STEP_MIN);           // 2nd leaking reading
        $leakDone = $leakAlertAt->addMinutes(42);
        $episodeStart = $endLocal->subDays(6)->setTime(18, 15)->utc();   // after the 18:00 irrigation

        $m = 50.0;
        $below = 0;
        $above = 0;
        $dryAlertAt = null;
        $dryResolvedAt = null;
        $leakQuiet = 0;

        for ($i = 0; $i < $steps; $i++) {
            $t = $start->addMinutes($i * self::STEP_MIN);
            $local = $t->setTimezone(self::TZ);
            $hour = $local->hour + $local->minute / 60;
            $minuteOfDay = $local->hour * 60 + $local->minute;

            $cycle = cos(2 * M_PI * ($hour - 14) / 24);
            $temperature = round(30 + 6 * $cycle + $this->random->getFloat(-0.6, 0.6), 1);
            $humidity = round(37.5 - 17.5 * $cycle + $this->random->getFloat(-2, 2), 1);

            $scheduled = ($minuteOfDay >= 360 && $minuteOfDay < 375)
                || ($minuteOfDay >= 1080 && $minuteOfDay < 1095);

            $pumpOn = $scheduled;
            $flow = 0.0;
            $leaking = false;
            $decay = 1.0;

            switch ($this->scenario) {
                case 'drying_out':
                    $pumpOn = $scheduled && $t->lt($irrigationStopsAt);
                    break;

                case 'leak':
                    if ($t->gte($leakStart) && $t->lte($leakAlertAt->addMinutes(20))) {
                        $leaking = true; // flow with the pump off
                    } elseif ($t->eq($leakAlertAt->addMinutes(30))) {
                        $pumpOn = true; // the worker tests the repaired line
                    }
                    break;

                case 'dry_fixed':
                    $fixed = $dryAlertAt !== null && $t->gte($dryAlertAt->addMinutes(65));
                    if ($t->gte($episodeStart) && ! $fixed) {
                        $decay = 2.5; // blocked drippers in hot, windy weather
                        $pumpOn = $dryAlertAt !== null && $t->gte($dryAlertAt->addMinutes(30)); // manual watering
                    }
                    break;
            }

            // Soil moisture model.
            $k = (0.0035 + 0.00025 * ($temperature - 24)) * $decay;
            $m = self::FLOOR + ($m - self::FLOOR) * exp(-$k);
            if ($pumpOn) {
                $flow = round($this->random->getFloat(1.5, 3.0), 3);
                $m = min(68.0, $m + $flow * 3.5);
            } elseif ($leaking) {
                $flow = round($this->random->getFloat(0.6, 0.9), 3);
                $m += 0.3;
            }

            $reported = max(0.0, min(100.0, $m + $this->random->getFloat(-0.3, 0.3)));
            $raw = (int) round($this->dryValue - $reported / 100 * ($this->dryValue - $this->wetValue));
            $pct = round(max(0, min(100, ($this->dryValue - $raw) / ($this->dryValue - $this->wetValue) * 100)), 1);

            $this->rows[] = [
                'node_id' => $this->nodeId,
                'park_id' => $this->parkId,
                'moisture_raw' => $raw,
                'moisture_pct' => $pct,
                'flow' => $flow,
                'temperature' => $temperature,
                'humidity' => $humidity,
                'pump_on' => $pumpOn,
                'water_level' => null,
                'source' => 'simulated',
                'created_at' => $t->format('Y-m-d H:i:s'),
            ];

            // What the rules engine would have seen (decision-table.md rules 1 and 4).
            $below = $pct < $this->moistureMin ? $below + 1 : 0;
            $above = $pct >= $this->moistureMin ? $above + 1 : 0;

            if (in_array($this->scenario, ['drying_out', 'dry_fixed'], true)) {
                if ($dryAlertAt === null && $below === 3) {
                    $dryAlertAt = $t;
                }
                if ($dryAlertAt !== null && $dryResolvedAt === null && $above === 3) {
                    $dryResolvedAt = $t;
                }
            }

            if ($this->scenario === 'leak' && $t->gt($leakAlertAt) && ! isset($this->events['resolved_at'])) {
                $leakQuiet = (! $pumpOn && $flow <= 0.2) ? $leakQuiet + 1 : 0;
                if ($leakQuiet === 2) {
                    $this->events['resolved_at'] = $t;
                }
            }
        }

        switch ($this->scenario) {
            case 'drying_out':
                $this->require($dryAlertAt !== null && $dryResolvedAt === null, 'moisture must fall below the minimum and stay there');
                $this->events['alert_at'] = $dryAlertAt;
                break;

            case 'leak':
                $this->require(isset($this->events['resolved_at']), 'the leak must resolve');
                $this->events += ['alert_at' => $leakAlertAt, 'done_at' => $leakDone, 'leak_flow' => $this->flowAt($leakAlertAt)];
                break;

            case 'dry_fixed':
                $this->require($dryAlertAt !== null && $dryResolvedAt !== null, 'the dry episode must fire and resolve');
                $this->events += ['alert_at' => $dryAlertAt, 'done_at' => $dryAlertAt->addMinutes(65), 'resolved_at' => $dryResolvedAt];
                break;
        }

        return ['rows' => $this->rows, 'events' => $this->events];
    }

    /** One column of the reading at $t (for alert data). */
    public static function valueAt(array $rows, CarbonImmutable $t, string $column): mixed
    {
        $stamp = $t->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            if ($row['created_at'] === $stamp) {
                return $row[$column];
            }
        }

        return null;
    }

    /**
     * Litres pumped between $from and $to: flow (L/min) x 10 minutes for every
     * reading with the pump on (data-model.md, tasks.water_used_l).
     */
    public static function waterUsed(array $rows, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $from = $from->format('Y-m-d H:i:s');
        $to = $to->format('Y-m-d H:i:s');
        $litres = 0.0;

        foreach ($rows as $row) {
            if ($row['pump_on'] && $row['created_at'] >= $from && $row['created_at'] <= $to) {
                $litres += $row['flow'] * self::STEP_MIN;
            }
        }

        return round($litres, 2);
    }

    private function flowAt(CarbonImmutable $t): ?float
    {
        return self::valueAt($this->rows, $t, 'flow');
    }

    private function require(bool $ok, string $what): void
    {
        if (! $ok) {
            throw new RuntimeException("Reading simulation for {$this->key} failed: {$what}.");
        }
    }
}
