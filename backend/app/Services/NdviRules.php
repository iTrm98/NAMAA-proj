<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\NdviReading;
use App\Models\Park;
use App\Support\Namaa;
use Illuminate\Support\Facades\DB;

/**
 * ndvi_drop (plant, low): the newest NDVI value is 10 % or more below the
 * average of the values in the 4 weeks before it (at least 3 values), the
 * slow sign that a park is losing green cover. It resolves once a newer value
 * is back within 5 % of that average. Run after every NDVI fetch.
 */
class NdviRules
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    /** @return array{latest: ?float, baseline: ?float, history: int, drop: ?float, outcome: string} */
    public function check(Park $park): array
    {
        return DB::transaction(function () use ($park) {
            $latest = $park->ndviReadings()->orderByDesc('date')->first();
            $open = Alert::where('park_id', $park->id)->where('type', AlertType::NdviDrop)
                ->where('status', AlertStatus::Open)->lockForUpdate()->first();

            $result = ['latest' => $latest?->mean, 'baseline' => null, 'history' => 0, 'drop' => null, 'outcome' => 'no_data'];
            if (! $latest) {
                return $result;
            }

            // An outline of fewer than ndvi.min_pixels valid pixels cannot be measured reliably:
            // never raise ndvi_drop for it, and its values never count as history.
            if ($latest->tooSmall()) {
                if ($open) {
                    $this->alerts->resolve($open, 'ndvi_too_small');
                }

                return ['outcome' => 'too_small'] + $result;
            }

            $history = NdviReading::where('park_id', $park->id)
                ->where('pixels', '>=', (int) config('namaa.ndvi.min_pixels'))
                ->where('date', '<', $latest->date->toDateString())
                ->where('date', '>=', $latest->date->copy()->subDays((int) Namaa::rule('ndvi_baseline_days'))->toDateString())
                ->pluck('mean');
            $result['history'] = $history->count();

            if ($history->count() < (int) Namaa::rule('ndvi_min_history')) {
                return ['outcome' => 'not_enough_history'] + $result;
            }

            $baseline = (float) $history->avg();
            $drop = $baseline > 0 ? ($baseline - $latest->mean) / $baseline : 0.0;
            $result = ['baseline' => round($baseline, 3), 'drop' => round($drop, 3)] + $result;
            $data = [
                'ndvi' => $latest->mean,
                'ndvi_date' => $latest->date->toDateString(),
                'baseline' => round($baseline, 3),
                'drop_pct' => round($drop * 100, 1),
                'history' => $history->count(),
            ];

            if ($drop >= (float) Namaa::rule('ndvi_drop_ratio')) {
                if ($open) {
                    $this->alerts->touch($open, $data);

                    return ['outcome' => 'still_open'] + $result;
                }
                $this->alerts->raise($park, null, AlertType::NdviDrop, AlertType::NdviDrop->severity(), $data);

                return ['outcome' => 'raised'] + $result;
            }

            if ($open && $drop <= (float) Namaa::rule('ndvi_recover_ratio')) {
                $this->alerts->resolve($open, 'ndvi_recovered');

                return ['outcome' => 'resolved'] + $result;
            }

            return ['outcome' => $open ? 'still_open' : 'ok'] + $result;
        });
    }
}
