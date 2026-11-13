<?php

namespace App\Services;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\GeometrySource;
use App\Models\Alert;
use App\Models\NdviComposite;
use App\Models\Park;
use App\Support\Namaa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ndvi_drop (plant, low), v2 (decision-table.md, rule 10): the 30-day composite headline is far below the
 * park's own baseline (z <= -2 and 10 % or more), judged only at high or medium confidence, on at least
 * two clear dates, on a traced outline. Never from a partial view. Run after every NDVI fetch.
 */
class NdviRules
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    /** @return array{latest: ?float, baseline: ?float, history: int, drop: ?float, outcome: string} */
    public function check(Park $park): array
    {
        if (! Schema::hasTable('ndvi_composites')) {
            return ['latest' => null, 'baseline' => null, 'history' => 0, 'drop' => null, 'outcome' => 'no_data'];
        }

        return DB::transaction(function () use ($park) {
            $c = NdviComposite::where('park_id', $park->id)->orderByDesc('date')->first();
            $open = Alert::where('park_id', $park->id)->where('type', AlertType::NdviDrop)
                ->where('status', AlertStatus::Open)->lockForUpdate()->first();

            $result = [
                'latest' => $c?->value, 'baseline' => $c?->baseline_median, 'history' => $c?->baseline_n ?? 0,
                'drop' => $c?->rel_drop, 'outcome' => 'no_data',
            ];
            if (! $c) {
                return $result;
            }

            if ($c->level === NdviConfidence::NOT_MEASURABLE) {
                if ($open) {
                    $this->alerts->resolve($open->setRelation('park', $park), 'not_measurable');
                }

                return ['outcome' => 'not_measurable'] + $result;
            }
            if ($park->geometry_source !== GeometrySource::Traced) {
                if ($open) {
                    $this->alerts->resolve($open->setRelation('park', $park), 'unverified_outline');
                }

                return ['outcome' => 'unverified_outline'] + $result;
            }
            // A partial view or a doubtful image: an open alert stays as it is, nothing new is raised.
            if (! NdviConfidence::trusted($c->level)) {
                return ['outcome' => 'low_confidence'] + $result;
            }
            if ($c->baseline_kind === 'none' || $c->z === null || $c->rel_drop === null) {
                return ['outcome' => 'not_enough_history'] + $result;
            }

            $data = [
                'ndvi' => $c->value,
                'baseline' => $c->baseline_median,
                'mad' => $c->baseline_mad,
                'z' => $c->z,
                'drop_pct' => round($c->rel_drop * 100, 1),
                'level' => $c->level,
                'confidence' => $c->confidence,
                'dates' => $c->dates_count,
                'newest_date' => $c->date->toDateString(),
            ];

            $dropped = $c->z <= (float) config('namaa.ndvi.drop_z') && $c->rel_drop >= (float) Namaa::rule('ndvi_drop_ratio');
            if ($dropped && $c->dates_count >= 2) {
                if ($open) {
                    $this->alerts->touch($open, $data);

                    return ['outcome' => 'still_open'] + $result;
                }
                $this->alerts->raise($park, null, AlertType::NdviDrop, AlertType::NdviDrop->severity(), $data);

                return ['outcome' => 'raised'] + $result;
            }

            if ($open && ($c->z >= (float) config('namaa.ndvi.recover_z') || $c->rel_drop <= (float) Namaa::rule('ndvi_recover_ratio'))) {
                $this->alerts->resolve($open->setRelation('park', $park), 'ndvi_recovered');

                return ['outcome' => 'resolved'] + $result;
            }

            return ['outcome' => $open ? 'still_open' : 'ok'] + $result;
        });
    }
}
