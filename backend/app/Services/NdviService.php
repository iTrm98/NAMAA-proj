<?php

namespace App\Services;

use App\Events\NdviUpdated;
use App\Models\NdviReading;
use App\Models\Park;
use App\Support\Realtime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * @return array{park_id: int, from: string, to: string, values: int, inserted: int, updated: int, rule: array<string, mixed>, dates: list<array<string, mixed>>}
     *
     * @throws VisionUnavailable
     */
    public function fetch(Park $park, int $days, bool $replace = false): array
    {
        $to = now('Asia/Riyadh')->toDateString();
        $from = now('Asia/Riyadh')->subDays(max(1, $days))->toDateString();
        $answer = $this->vision->ndviDetailed($park->id, $park->polygon, $from, $to);
        $values = $answer['values'];

        $dates = array_column($values, 'date');
        $existing = $dates
            ? NdviReading::where('park_id', $park->id)->whereIn('date', $dates)->count()
            : 0;

        $rows = array_map(fn (array $v) => [
            'park_id' => $park->id,
            'date' => $v['date'],
            'mean' => round((float) $v['mean'], 3),
            'pixels' => (int) $v['pixels'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $values);
        // replace (after an outline change): the window's old values were measured on
        // another outline, so they go, but only now that the new ones have arrived. An
        // empty answer (an outline too small for Sentinel-2's 10 m pixels, or all
        // cloudy) keeps the old values instead of leaving the park without NDVI.
        $removed = 0;
        $keptOld = $replace && $rows === [];
        DB::transaction(function () use ($park, $rows, $from, $replace, $keptOld, &$removed, &$existing) {
            if ($replace && ! $keptOld) {
                $removed = NdviReading::where('park_id', $park->id)->where('date', '>=', $from)->delete();
                $existing = 0;
            }
            if ($rows) {
                NdviReading::upsert($rows, ['park_id', 'date'], ['mean', 'pixels', 'updated_at']);
            }
        });

        $rule = $this->rules->check($park);
        $result = [
            'park_id' => $park->id,
            'from' => $from,
            'to' => $to,
            'values' => count($rows),
            'inserted' => count($rows) - $existing,
            'updated' => $existing,
            'replaced' => $replace ? $removed : null,
            'kept_old' => $keptOld,
            'rule' => $rule,
        ];

        $this->logger->log('ndvi.fetched', $park, ['after' => $result], companyId: $park->company_id);
        Realtime::send(new NdviUpdated($park->company_id, [
            'park_id' => $park->id,
            'ndvi' => $rule['latest'],
            'values' => count($rows),
        ]));

        // per satellite date, for the command output; not part of the activity log
        $result['dates'] = $answer['dates'];

        return $result;
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
