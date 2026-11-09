<?php

namespace App\Services\Rules;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Support\Namaa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a rule sees after a reading is stored: the park, the node, its newest
 * readings (newest first) and its open alerts by type.
 */
final class RuleContext
{
    private ?bool $stuck = null;

    /**
     * @param  Collection<int, Reading>  $recent  newest first
     * @param  array<string, Alert>  $open  open alerts of this node, keyed by type value
     */
    public function __construct(
        public readonly Park $park,
        public readonly Node $node,
        public readonly Reading $reading,
        public readonly Collection $recent,
        public array $open,
    ) {}

    /** The n newest readings, or null when the node has fewer than n. @return Collection<int, Reading>|null */
    public function last(int $n): ?Collection
    {
        return $this->recent->count() >= $n ? $this->recent->take($n) : null;
    }

    /** True when the n newest readings all match. */
    public function lastAll(int $n, callable $test): bool
    {
        $readings = $this->last($n);

        return $readings !== null && $readings->every($test);
    }

    public function hasOpen(AlertType $type): bool
    {
        return isset($this->open[$type->value]);
    }

    /** The open dry problem of this node: a `dry` alert, or the same alert after it became `heat_stress`. */
    public function openDry(): ?Alert
    {
        return $this->open[AlertType::Dry->value] ?? $this->open[AlertType::HeatStress->value] ?? null;
    }

    /**
     * The raw moisture value has been exactly the same for the stuck window
     * (1 h, 2 min in demo mode) while readings kept arriving: every reading
     * in the window (at least half of the expected number) has the current
     * raw value, and the oldest of them is at the start of the window.
     * Cheap path: two different newest values = not stuck (no query).
     */
    public function stuck(): bool
    {
        if ($this->stuck !== null) {
            return $this->stuck;
        }

        $raw = $this->reading->moisture_raw;
        $two = $this->last(2);

        if ($raw === null || $two === null || $two->last()->moisture_raw !== $raw) {
            return $this->stuck = false;
        }

        $windowSeconds = Namaa::timing('stuck_seconds');
        $interval = max(1, Namaa::timing('interval_s'));
        $windowStart = $this->reading->created_at->copy()->subSeconds($windowSeconds);
        // Readings must keep arriving: at least half of the expected readings in the window.
        $minReadings = max(3, intdiv(intdiv($windowSeconds, $interval), 2));

        $stats = Reading::where('node_id', $this->node->id)
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<=', $this->reading->created_at)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN moisture_raw = ? THEN 1 ELSE 0 END) AS same, MIN(created_at) AS oldest', [$raw])
            ->first();

        // Every reading of the window has the value, and the oldest one is at
        // the start of the window (within one interval).
        return $this->stuck = (int) $stats->total >= $minReadings
            && (int) $stats->same === (int) $stats->total
            && Carbon::parse($stats->oldest, 'UTC')->lte($windowStart->copy()->addSeconds($interval));
    }

    /** Raw moisture is missing or at the ADC limits: a broken or unplugged sensor. */
    public static function faulty(Reading $r): bool
    {
        return $r->moisture_raw === null
            || $r->moisture_pct === null
            || $r->moisture_raw <= Namaa::rule('fault_raw_low')
            || $r->moisture_raw >= Namaa::rule('fault_raw_high');
    }

    public static function flowing(Reading $r): bool
    {
        return $r->flow !== null && $r->flow >= Namaa::rule('flow_zero_lpm');
    }

    /** The pump is running or water is flowing (irrigation in progress). */
    public static function irrigating(Reading $r): bool
    {
        return $r->pump_on === true || self::flowing($r);
    }

    /** Default readings in a row to raise (no_flow, leak, sensor_fault, over on pump nodes). */
    public static function confirm(): int
    {
        return (int) Namaa::rule('confirm_readings');
    }

    public static function resolveCount(): int
    {
        return (int) Namaa::rule('resolve_readings');
    }

    /** Readings in the blockage window: 2 min at 30 s = 4; demo 15 s at 5 s = 3. */
    public static function blockageReadings(): int
    {
        return max(1, (int) ceil(Namaa::timing('blockage_seconds') / Namaa::timing('interval_s')));
    }

    /** How many newest readings the engine loads for the rules. */
    public static function window(): int
    {
        return max(
            self::confirm(),
            self::resolveCount(),
            (int) Namaa::rule('dry_confirm_readings'),
            (int) Namaa::rule('over_no_pump_confirm_readings'),
            (int) Namaa::rule('battery_confirm_readings'),
            self::blockageReadings(),
        );
    }
}
