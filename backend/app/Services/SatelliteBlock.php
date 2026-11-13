<?php

namespace App\Services;

use App\Models\NdviComposite;
use App\Models\NdviReading;
use App\Models\Park;

/** The `satellite` block of a park response, built from its newest stored composite (no query when loaded). */
class SatelliteBlock
{
    /** @return array<string, mixed>|null */
    public static function for(Park $park): ?array
    {
        if (! $park->relationLoaded('latestComposite')) {
            return null;
        }
        /** @var NdviComposite|null $c */
        $c = $park->latestComposite;
        if (! $c) {
            return null;
        }

        $measurable = $c->level !== NdviConfidence::NOT_MEASURABLE && $c->value !== null;
        $imageDate = $c->value !== null ? $c->date->toDateString() : null;

        return [
            'measurable' => $measurable,
            'green_cover_pct' => $measurable ? $c->green_cover_pct : null,
            'change_vs_normal_pct' => $measurable && $c->rel_drop !== null ? round(-$c->rel_drop * 100, 1) : null,
            'ndvi' => $measurable ? $c->value : null,
            'ndvi_word' => $measurable ? NdviConfidence::word($c->value) : null,
            'confidence' => ['level' => $c->level, 'score' => $c->confidence, 'reasons' => $c->reasons ?? []],
            'image_date' => $imageDate,
            'age_days' => $imageDate ? NdviService::ageDays($imageDate, now('Asia/Riyadh')->startOfDay()) : null,
            'measured_on' => $c->measured_on,
            'flags' => $c->flags ?? [],
            'composite' => ['date_from' => $c->date_from->toDateString(), 'date_to' => $c->date_to->toDateString(), 'dates' => $c->dates_count],
            'baseline' => $c->baseline_kind !== 'none' && $c->baseline_median !== null
                ? ['median' => $c->baseline_median, 'mad' => $c->baseline_mad, 'n' => $c->baseline_n, 'kind' => $c->baseline_kind]
                : null,
            'pure_pixels' => $c->pure_pixels,
            'has_pixels' => $measurable,
        ];
    }

    /**
     * The legacy `ndvi` field: the composite headline when there is one, else the newest stored value.
     *
     * @return array{mean: ?float, date: string, pixels: int, too_small: bool}|null
     */
    public static function legacy(Park $park): ?array
    {
        if ($park->relationLoaded('latestComposite') && ($c = $park->latestComposite)) {
            $small = $c->level === NdviConfidence::NOT_MEASURABLE && $c->pure_pixels < (int) config('namaa.ndvi.min_pure_pixels');

            return ['mean' => $c->value, 'date' => $c->date->toDateString(), 'pixels' => $c->pure_pixels, 'too_small' => $small];
        }
        /** @var NdviReading|null $r */
        $r = $park->relationLoaded('latestNdvi') ? $park->latestNdvi : null;

        return $r?->summary();
    }
}
