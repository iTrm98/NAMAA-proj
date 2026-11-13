<?php

namespace App\Services;

use App\Events\NdviUpdated;
use App\Enums\GeometrySource;
use App\Enums\VerificationStatus;
use App\Models\NdviComposite;
use App\Models\NdviGrid;
use App\Models\NdviReading;
use App\Models\Park;
use App\Support\Realtime;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fetches Sentinel-2 NDVI for one park from the vision service, upserts it
 * into ndvi_readings (one row per park and date, so re-running is harmless),
 * then runs the ndvi_drop rule. Progress of a "fetch all" run is kept in the
 * cache for the demo page.
 */
class NdviService
{
    public const RUN_KEY = 'namaa:ndvi:run';

    public function __construct(
        private readonly VisionClient $vision,
        private readonly NdviRules $rules,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Fetch, store and judge one park. The demo stand and archived parks are never measured.
     *
     * @return array<string, mixed>
     *
     * @throws VisionUnavailable
     */
    public function fetch(Park $park, int $days, bool $replace = false): array
    {
        $today = now('Asia/Riyadh')->startOfDay();
        $to = $today->toDateString();
        $from = $today->copy()->subDays(max(1, $days))->toDateString();
        $base = ['park_id' => $park->id, 'from' => $from, 'to' => $to, 'values' => 0, 'inserted' => 0, 'updated' => 0, 'replaced' => null, 'kept_old' => false, 'dates' => [], 'headline' => null, 'level' => null, 'confidence' => null, 'green_cover_pct' => null, 'composite_dates' => 0, 'skipped' => null, 'rule' => ['latest' => null, 'baseline' => null, 'history' => 0, 'drop' => null, 'outcome' => 'skipped']];

        if (! $park->isSatelliteEligible() || empty($park->polygon)) {
            return ['skipped' => 'not_a_park'] + $base;
        }
        if (! NdviReading::hasV2Columns() || ! Schema::hasTable('ndvi_composites')) {
            return ['skipped' => 'migration_pending'] + $base;
        }

        $answer = $this->vision->ndvi($park->id, $park->polygon, $park->green_zone ?: null, $from, $to, pixels: true);
        $values = $answer['values'];
        $geo = $answer['geometry'];
        $measuredOn = (string) ($answer['measured_on'] ?? 'boundary');
        $pure = (int) ($geo['pure_pixels'] ?? 0);
        $usual = (float) ($geo['usual_pixels'] ?? 0);
        $width = isset($geo['inner_width_m']) ? (float) $geo['inner_width_m'] : null;
        $ctx = [
            'geometry_source' => $park->geometry_source->value,
            'traced' => $park->geometry_source === GeometrySource::Traced && $measuredOn === 'green_zone',
            'verified' => $park->verification_status === VerificationStatus::Verified,
            'measured_on' => $measuredOn,
            'inner_width_m' => $width,
            'pure_pixels' => $pure,
        ];

        $rows = [];
        foreach ($values as $v) {
            $conf = NdviConfidence::evaluate($ctx + [
                'clear_fraction' => (float) ($v['clear_fraction'] ?? 0),
                'age_days' => self::ageDays($v['date'], $today),
                'dates' => 1,
                'mad' => null,
            ]);
            $rows[] = [
                'park_id' => $park->id,
                'date' => $v['date'],
                'mean' => round((float) $v['mean'], 3),
                'median' => round((float) ($v['median'] ?? $v['mean']), 3),
                'p90' => isset($v['p90']) ? round((float) $v['p90'], 3) : null,
                'pixels' => (int) ($v['pixels'] ?? 0),
                'pure_pixels' => (int) ($v['pure_pixels'] ?? 0),
                'weighted_pixels' => $v['weighted_pixels'] ?? null,
                'clear_pixels' => $v['clear_pixels'] ?? null,
                'usual_pixels' => $usual,
                'clear_fraction' => $v['clear_fraction'] ?? null,
                'green_cover_pct' => $v['green_cover_pct'] ?? null,
                'measured_on' => $measuredOn,
                'method' => 'v2',
                'confidence' => $conf['score'],
                'level' => $conf['level'],
                'reasons' => json_encode($conf['reasons']),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $dates = array_column($values, 'date');
        $existing = $dates ? NdviReading::where('park_id', $park->id)->where('method', 'v2')->whereIn('date', $dates)->count() : 0;
        $keptOld = $replace && $rows === [] && empty($answer['composite']);
        $removed = 0;

        DB::transaction(function () use ($park, $rows, $from, $replace, $keptOld, &$removed, &$existing) {
            if ($replace && ! $keptOld) {
                $removed = NdviReading::where('park_id', $park->id)->where('method', 'v2')->where('date', '>=', $from)->delete();
                NdviComposite::where('park_id', $park->id)->where('date', '>=', $from)->delete();
                $existing = 0;
            }
            if ($rows) {
                $cols = ['mean', 'median', 'p90', 'pixels', 'pure_pixels', 'weighted_pixels', 'clear_pixels', 'usual_pixels', 'clear_fraction', 'green_cover_pct', 'measured_on', 'method', 'confidence', 'level', 'reasons', 'updated_at'];
                NdviReading::upsert($rows, ['park_id', 'date'], $cols);
                // A successful v2 answer replaces the old rectangle (v1) and seeded values.
                NdviReading::where('park_id', $park->id)->where('method', 'v1')->delete();
            }
        });

        $composite = $this->storeComposite($park, $answer, $ctx, $today);
        $this->storeGrid($park, $answer['pixels'] ?? null, $composite);

        $rule = $this->rules->check($park->refresh());
        $result = array_merge($base, [
            'values' => count($rows),
            'inserted' => count($rows) - $existing,
            'updated' => $existing,
            'replaced' => $replace ? $removed : null,
            'kept_old' => $keptOld,
            'headline' => $composite?->value,
            'level' => $composite?->level,
            'confidence' => $composite?->confidence,
            'green_cover_pct' => $composite?->green_cover_pct,
            'composite_dates' => $composite?->dates_count ?? 0,
            'rule' => $rule,
        ]);

        $log = $result;
        unset($log['dates']);
        $this->logger->log('ndvi.fetched', $park, ['after' => $log], companyId: $park->company_id);
        Realtime::send(new NdviUpdated($park->company_id, [
            'park_id' => $park->id,
            'ndvi' => $composite?->value,
            'values' => count($rows),
            'headline' => $composite?->value,
            'level' => $composite?->level,
        ]));

        // per satellite date, for the command output; not part of the activity log
        $result['dates'] = $answer['dates'];

        return $result;
    }

    /**
     * The composite row of this run (the headline). With no clear image in the window the previous
     * composite stays (its confidence is re-judged with today as the age); a park that cannot be measured
     * gets a "not measurable" row.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $ctx
     */
    private function storeComposite(Park $park, array $answer, array $ctx, Carbon $today): ?NdviComposite
    {
        $comp = $answer['composite'] ?? null;
        $minPure = (int) config('namaa.ndvi.min_pure_pixels');
        $flags = array_values($answer['flags'] ?? []);
        $common = [
            'window_days' => (int) config('namaa.ndvi.composite_days'),
            'pure_pixels' => $ctx['pure_pixels'],
            'usual_pixels' => $answer['geometry']['usual_pixels'] ?? null,
            'inner_width_m' => $ctx['inner_width_m'],
            'measured_on' => $ctx['measured_on'],
            'flags' => $flags,
        ];

        if ($comp !== null) {
            $dateFrom = $comp['date_from'];
            $used = collect($answer['values'])->filter(fn ($v) => $v['date'] > $dateFrom && (float) ($v['clear_fraction'] ?? 0) >= 0.7)->values();
            $medians = $used->map(fn ($v) => (float) ($v['median'] ?? $v['mean']))->all();
            $mad = count($medians) > 1 ? NdviBaseline::median(array_map(fn ($m) => abs($m - NdviBaseline::median($medians)), $medians)) : null;
            $cf = $used->isNotEmpty() ? round((float) $used->avg('clear_fraction'), 3) : (float) $comp['clear_fraction'];
            $headline = (float) $comp['median'];
            $history = NdviReading::where('park_id', $park->id)->where('method', 'v2')
                ->where('date', '<=', $dateFrom)->where('date', '>=', $today->copy()->subDays(1095)->toDateString())
                ->where('clear_fraction', '>=', 0.7)->whereNotNull('median')
                ->get(['date', 'median'])->map(fn ($r) => ['date' => $r->date->toDateString(), 'median' => $r->median])->all();
            $baseline = NdviBaseline::compute($history, $today->toDateString(), $headline);
            $conf = NdviConfidence::evaluate($ctx + [
                'clear_fraction' => $cf,
                'age_days' => self::ageDays($comp['newest_date'], $today),
                'dates' => (int) $comp['dates'],
                'mad' => $mad,
            ]);
            if ($baseline['kind'] === 'none' && $conf['level'] !== NdviConfidence::NOT_MEASURABLE) {
                $conf['reasons'][] = NdviConfidence::reason('no_baseline');
            }

            $row = $this->upsertComposite($park, $comp['newest_date'], $common + [
                'date_from' => $dateFrom,
                'date_to' => $comp['date_to'],
                'dates_count' => (int) $comp['dates'],
                'value' => round($headline, 3),
                'mean' => $comp['mean'] ?? null,
                'p90' => $comp['p90'] ?? null,
                'green_cover_pct' => $comp['green_cover_pct'] ?? null,
                'clear_fraction' => $cf,
                'consistency_mad' => $mad === null ? null : round($mad, 3),
                'baseline_median' => $baseline['median'],
                'baseline_mad' => $baseline['mad'],
                'baseline_n' => $baseline['n'],
                'baseline_kind' => $baseline['kind'],
                'z' => $baseline['z'],
                'rel_drop' => $baseline['rel_drop'],
                'confidence' => $conf['score'],
                'level' => $conf['level'],
                'reasons' => $conf['reasons'],
            ]);
            NdviComposite::where('park_id', $park->id)->whereNull('value')->delete();

            return $row;
        }

        $previous = NdviComposite::where('park_id', $park->id)->whereNotNull('value')->orderByDesc('date')->first();
        if ($previous && $ctx['pure_pixels'] >= $minPure) {
            $conf = NdviConfidence::evaluate($ctx + [
                'clear_fraction' => (float) $previous->clear_fraction,
                'age_days' => self::ageDays($previous->date->toDateString(), $today),
                'dates' => $previous->dates_count,
                'mad' => $previous->consistency_mad,
            ]);
            if ($previous->baseline_kind === 'none' && $conf['level'] !== NdviConfidence::NOT_MEASURABLE) {
                $conf['reasons'][] = NdviConfidence::reason('no_baseline');
            }
            $previous->update($common + ['confidence' => $conf['score'], 'level' => $conf['level'], 'reasons' => $conf['reasons']]);

            return $previous;
        }

        $conf = NdviConfidence::evaluate($ctx + ['clear_fraction' => 0, 'age_days' => 0, 'dates' => 1, 'mad' => null]);
        if ($conf['level'] !== NdviConfidence::NOT_MEASURABLE) {
            $conf = ['score' => null, 'level' => NdviConfidence::NOT_MEASURABLE, 'reasons' => [NdviConfidence::reason('no_image')]];
        }
        $windowDays = (int) config('namaa.ndvi.composite_days');

        return $this->upsertComposite($park, $today->toDateString(), $common + [
            'date_from' => $today->copy()->subDays($windowDays)->toDateString(),
            'date_to' => $today->toDateString(),
            'dates_count' => 0,
            'value' => null,
            'confidence' => $conf['score'],
            'level' => $conf['level'],
            'reasons' => $conf['reasons'],
        ]);
    }

    /** Whole days from a Riyadh calendar date to today (Riyadh midnight). */
    public static function ageDays(string $date, Carbon $today): int
    {
        return (int) Carbon::parse($date.' 00:00', 'Asia/Riyadh')->diffInDays($today, true);
    }

    /** One composite per park and date (whereDate keeps this working on SQLite, which stores dates as datetimes). @param array<string, mixed> $attrs */
    private function upsertComposite(Park $park, string $date, array $attrs): NdviComposite
    {
        $row = NdviComposite::where('park_id', $park->id)->whereDate('date', $date)->first();
        if ($row) {
            $row->update($attrs);

            return $row;
        }

        return NdviComposite::create(['park_id' => $park->id, 'date' => $date] + $attrs);
    }

    /** The pixels of the newest composite, for the map. @param array<string, mixed>|null $fc */
    private function storeGrid(Park $park, ?array $fc, ?NdviComposite $composite): void
    {
        if (! $fc || empty($fc['features']) || ! Schema::hasTable('ndvi_grids')) {
            return;
        }
        NdviGrid::updateOrCreate(['park_id' => $park->id], [
            'date' => $composite?->date?->toDateString() ?? now('Asia/Riyadh')->toDateString(),
            'pixels' => count($fc['features']),
            'geojson' => $fc,
        ]);
    }

    /**
     * The pixels for the map: the stored grid only (built by the fetch job; a GET never calls the vision service).
     *
     * @return array<string, mixed>|null null when no grid is stored
     */
    public function pixels(Park $park): ?array
    {
        if (! $park->isSatelliteEligible()) {
            return null;
        }
        $grid = NdviGrid::where('park_id', $park->id)->first();
        if (! $grid) {
            return null;
        }

        $fc = $grid->geojson;
        $fc['meta'] = ($fc['meta'] ?? []) + ['pixels' => $grid->pixels];
        $fc['meta']['date'] = $grid->date->toDateString();

        return $fc;
    }

    /**
     * Start a progress record for a run over these parks (demo page).
     *
     * @param  list<int>  $parkIds
     * @return array<string, mixed>
     */
    public static function startRun(array $parkIds, int $days): array
    {
        $run = [
            'id' => (string) Str::uuid(),
            'days' => $days,
            'total' => count($parkIds),
            'done' => 0,
            'failed' => 0,
            'values' => 0,
            'parks' => array_fill_keys(array_map('strval', $parkIds), 'queued'),
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];
        Cache::put(self::RUN_KEY, $run, now()->addHour());

        return $run;
    }

    /** One park of the run finished: done, skipped or failed (for good). */
    public static function progress(?string $runId, int $parkId, string $state, int $values = 0): void
    {
        if ($runId === null) {
            return;
        }
        Cache::lock(self::RUN_KEY.':lock', 5)->block(5, function () use ($runId, $parkId, $state, $values) {
            $run = Cache::get(self::RUN_KEY);
            if (! $run || $run['id'] !== $runId) {
                return;
            }
            $run['parks'][(string) $parkId] = $state;
            if ($state === 'failed') {
                $run['failed']++;
            } else {
                $run['done']++;
                $run['values'] += $values;
            }
            if ($run['done'] + $run['failed'] >= $run['total']) {
                $run['finished_at'] = now()->toIso8601String();
            }
            Cache::put(self::RUN_KEY, $run, now()->addHour());
        });
    }

    /** @return array<string, mixed>|null */
    public static function currentRun(): ?array
    {
        return Cache::get(self::RUN_KEY);
    }
}
