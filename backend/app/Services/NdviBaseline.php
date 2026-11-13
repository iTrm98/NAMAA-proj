<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * A park's own normal (decision-table.md, "Baseline"): median and MAD of its earlier clear
 * single-date medians, seasonal when enough years of data exist. Pure: history is passed in.
 */
class NdviBaseline
{
    /**
     * @param  list<array{date: string, median: float}>  $history  v2 single-date medians with clear fraction >= 0.7, older than the composite window
     * @return array{median: ?float, mad: ?float, n: int, kind: string, z: ?float, rel_drop: ?float}
     */
    public static function compute(array $history, string $asOf, ?float $headline): array
    {
        $today = Carbon::parse($asOf);
        $recent = array_values(array_filter($history, fn ($h) => Carbon::parse($h['date'])->gte($today->copy()->subDays(365))));

        $seasonal = array_values(array_filter($history, function ($h) use ($today) {
            $d = Carbon::parse($h['date']);
            if ($d->year >= $today->year || $d->gte($today->copy()->subDays(330))) {
                return false;
            }
            $diff = abs($d->dayOfYear - $today->dayOfYear);

            return min($diff, 365 - $diff) <= 45;
        }));

        [$values, $kind] = count($seasonal) >= 6 ? [$seasonal, 'seasonal'] : [$recent, 'recent'];
        if (count($values) < (int) config('namaa.ndvi.baseline_min_n')) {
            return ['median' => null, 'mad' => null, 'n' => count($values), 'kind' => 'none', 'z' => null, 'rel_drop' => null];
        }

        $nums = array_map(fn ($h) => (float) $h['median'], $values);
        $median = self::median($nums);
        $mad = self::median(array_map(fn ($v) => abs($v - $median), $nums));
        $out = ['median' => round($median, 3), 'mad' => round($mad, 3), 'n' => count($nums), 'kind' => $kind, 'z' => null, 'rel_drop' => null];
        if ($headline !== null) {
            $sigma = max(1.4826 * $mad, 0.02);
            $out['z'] = round(($headline - $median) / $sigma, 2);
            $out['rel_drop'] = $median > 0 ? round(($median - $headline) / $median, 3) : null;
        }

        return $out;
    }

    /** @param list<float> $values */
    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
