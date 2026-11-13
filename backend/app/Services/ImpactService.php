<?php

namespace App\Services;

use App\Enums\AlertCategory;
use App\Enums\AlertType;
use App\Enums\NodeType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Support\Namaa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Impact figures for a set of parks over a period (admin: the company's parks,
 * municipality: every park or one company's):
 *
 *  - water (Sprint 3.1): NAMAA's pump time against a fixed irrigation schedule
 *    (config namaa.water_baseline, 2 x 20 min a day by default, an assumption),
 *    over the same measured time: only the time covered by the pump node's
 *    readings counts, for both sides. Parks with a pump and automatic irrigation
 *    on only (NAMAA waters them by itself); litres where flow is measured;
 *  - alerts raised in the period, by priority, category and type;
 *  - tasks finished in the period: done, verified %, median time to resolve,
 *    escalated, overdue;
 *  - how many parks are real and how many simulated, so the page can say so.
 *
 * The schedule is an assumption, to confirm with the Amanah or the operator.
 */
class ImpactService
{
    /**
     * @param  Collection<int, Park>  $parks
     * @return array<string, mixed>
     */
    public function summary(Collection $parks, int $days): array
    {
        $to = now();
        $from = now()->subDays($days);
        $ids = $parks->pluck('id')->all();

        $water = $this->measure($parks, $from, $to);
        $alerts = Alert::whereIn('park_id', $ids)->whereBetween('created_at', [$from, $to])
            ->get(['id', 'park_id', 'type', 'priority']);
        $tasks = Task::whereIn('park_id', $ids)
            ->where(fn ($q) => $q->whereBetween('done_at', [$from, $to])
                ->orWhereBetween('escalated_at', [$from, $to])
                ->orWhereBetween('overdue_at', [$from, $to]))
            ->get(['id', 'park_id', 'status', 'done_at', 'verified', 'time_to_resolve_min', 'escalated_at', 'overdue_at', 'water_used_l']);

        $perPark = $parks->map(function (Park $park) use ($water, $alerts, $tasks, $from, $to) {
            $parkTasks = $tasks->where('park_id', $park->id);

            return [
                'id' => $park->id,
                'name_ar' => $park->name_ar,
                'name_en' => $park->name_en,
                'source' => $park->source->value,
                'company' => $park->relationLoaded('company') ? ['id' => $park->company->id, 'name' => $park->company->name] : null,
                'water' => $this->water(collect([$park]), $water),
                'alerts' => $alerts->where('park_id', $park->id)->count(),
                'tasks' => $this->tasks($parkTasks, $from, $to),
                'ndvi' => SatelliteBlock::legacy($park),
            ];
        })->values();

        return [
            'period' => ['days' => $days, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'water' => $this->water($parks, $water),
            'alerts' => $this->alerts($alerts),
            'tasks' => $this->tasks($tasks, $from, $to),
            'source_mix' => [
                'real' => $parks->filter(fn (Park $p) => $p->source->value === 'real')->count(),
                'simulated' => $parks->filter(fn (Park $p) => $p->source->value === 'simulated')->count(),
            ],
            'parks' => $perPark->all(),
        ];
    }

    /**
     * Measured time, pump time and litres per park, from the pump node's readings:
     * each reading counts until the next one, unless that gap is longer than
     * impact.max_gap_seconds (simulator stopped, laptop off, board down): that
     * time is not measured, for NAMAA and for the schedule alike.
     *
     * @param  Collection<int, Park>  $parks
     * @return array<int, array{compared: bool, reason: ?string, measured_s: float, pump_s: float, cycles: int, litres: float, flow_measured: bool, expected_flow: ?float}>
     */
    private function measure(Collection $parks, Carbon $from, Carbon $to): array
    {
        $since = config('namaa.impact.water_since') ? Carbon::parse(config('namaa.impact.water_since'), 'UTC') : null;
        $start = $since && $since->gt($from) ? $since : $from;

        $pumpNodes = Node::whereIn('park_id', $parks->pluck('id'))->where('type', NodeType::Sensor)->where('has_pump', true)
            ->orderBy('id')->get(['id', 'park_id', 'expected_flow_l_min'])->unique('park_id')->keyBy('park_id');

        $result = [];
        foreach ($parks as $park) {
            $node = $pumpNodes->get($park->id);
            $reason = ! $node ? 'no_pump' : (! $park->auto_irrigation ? 'manual_irrigation' : null);
            $result[$park->id] = ['compared' => $reason === null, 'reason' => $reason, 'measured_s' => 0.0, 'pump_s' => 0.0, 'cycles' => 0,
                'litres' => 0.0, 'flow_measured' => false, 'expected_flow' => $node?->expected_flow_l_min !== null ? (float) $node->expected_flow_l_min : null];
        }

        $nodeIds = $pumpNodes->filter(fn (Node $n) => $result[$n->park_id]['compared'])->pluck('id')->values()->all();
        if ($nodeIds === [] || $start->gte($to)) {
            return $result;
        }

        // Seconds from each reading to the node's next one (window function: MySQL 8 and SQLite).
        $gap = DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(next_at) - julianday(created_at)) * 86400'
            : 'TIMESTAMPDIFF(MICROSECOND, created_at, next_at) / 1000000';
        $next = DB::table('readings')
            ->select(['node_id', 'park_id', 'flow', 'pump_on', 'created_at'])
            ->selectRaw('LEAD(created_at) OVER (PARTITION BY node_id ORDER BY created_at, id) AS next_at')
            ->selectRaw('LEAD(pump_on) OVER (PARTITION BY node_id ORDER BY created_at, id) AS next_pump')
            ->whereIn('node_id', $nodeIds)
            ->whereBetween('created_at', [$start, $to]);
        $gaps = DB::query()->fromSub($next, 'r')->whereNotNull('next_at')
            ->select(['park_id', 'flow', 'pump_on', 'next_pump'])->selectRaw("$gap AS gap");
        $rows = DB::query()->fromSub($gaps, 'g')
            ->where('gap', '<=', (int) config('namaa.impact.max_gap_seconds'))
            ->groupBy('park_id')
            ->selectRaw('park_id, SUM(gap) AS measured_s')
            ->selectRaw('SUM(CASE WHEN pump_on = 1 THEN gap ELSE 0 END) AS pump_s')
            ->selectRaw('SUM(CASE WHEN pump_on = 1 AND flow > 0 THEN flow * gap / 60 ELSE 0 END) AS litres')
            // A completed irrigation cycle: the pump was on and the node's next reading has it off.
            ->selectRaw('SUM(CASE WHEN pump_on = 1 AND next_pump = 0 THEN 1 ELSE 0 END) AS cycles')
            ->selectRaw('SUM(CASE WHEN flow IS NOT NULL THEN 1 ELSE 0 END) AS flow_rows')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->park_id;
            $result[$id]['measured_s'] = (float) $row->measured_s;
            $result[$id]['pump_s'] = (float) $row->pump_s;
            $result[$id]['cycles'] = (int) $row->cycles;
            $result[$id]['litres'] = (float) $row->litres;
            $result[$id]['flow_measured'] = (int) $row->flow_rows > 0;
        }

        return $result;
    }

    /** @param array<string, mixed> $m one park's measured figures */
    private static function enoughData(array $m): bool
    {
        return $m['cycles'] >= (int) config('namaa.impact.min_cycles')
            && $m['measured_s'] >= (int) config('namaa.impact.min_measured_hours') * 3600;
    }

    /** The fixed irrigation schedule of a park (config, default 2 x 20 min a day). @return array{sessions_per_day: int, minutes_per_session: int} */
    public static function schedule(Park $park): array
    {
        $baseline = config('namaa.water_baseline');

        return ($baseline['parks'][$park->id] ?? []) + $baseline['default'];
    }

    /**
     * @param  Collection<int, Park>  $parks
     * @param  array<int, array<string, mixed>>  $measured
     * @return array<string, mixed>
     */
    private function water(Collection $parks, array $measured): array
    {
        // `candidates`: parks NAMAA waters by itself and that have measured time. Only those with
        // enough data (>= 1 completed irrigation cycle and >= min_measured_hours measured) count
        // for the saving: no fake 100 % from a pump that has not run yet.
        $candidates = $parks->filter(fn (Park $p) => $measured[$p->id]['compared'] && $measured[$p->id]['measured_s'] > 0);
        $compared = $candidates->filter(fn (Park $p) => self::enoughData($measured[$p->id]));
        $scheduled = function (Park $p) use ($measured): float {
            $s = self::schedule($p);

            return $measured[$p->id]['measured_s'] / 86400 * $s['sessions_per_day'] * $s['minutes_per_session'];
        };

        $measuredS = $candidates->sum(fn (Park $p) => $measured[$p->id]['measured_s']);
        $pumpMin = $compared->sum(fn (Park $p) => $measured[$p->id]['pump_s']) / 60;
        $baselineMin = $compared->sum($scheduled);

        // Litres: NAMAA's from the flow sensor, the schedule's at the pump's expected flow.
        $withFlow = $compared->filter(fn (Park $p) => $measured[$p->id]['flow_measured'] && $measured[$p->id]['expected_flow'] !== null);
        $used = $withFlow->sum(fn (Park $p) => $measured[$p->id]['litres']);
        $baselineL = $withFlow->sum(fn (Park $p) => $scheduled($p) * $measured[$p->id]['expected_flow']);

        $one = $parks->count() === 1 ? $measured[$parks->first()->id] : null;
        $since = config('namaa.impact.water_since');

        return [
            'method' => 'pump_time_vs_schedule',
            'schedule' => $parks->count() === 1 ? self::schedule($parks->first()) : config('namaa.water_baseline.default'),
            'measured_hours' => round($measuredS / 3600, 1),
            'pump_min' => round($pumpMin, 1),
            'baseline_min' => round($baselineMin, 1),
            'enough_data' => $compared->isNotEmpty(),
            'min_measured_hours' => (int) config('namaa.impact.min_measured_hours'),
            'min_cycles' => (int) config('namaa.impact.min_cycles'),
            'cycles' => $candidates->sum(fn (Park $p) => $measured[$p->id]['cycles']),
            'parks_not_enough_data' => $candidates->count() - $compared->count(),
            'saved_min' => $baselineMin > 0 ? round($baselineMin - $pumpMin, 1) : null,
            'saved_pct' => $baselineMin > 0 ? round((1 - $pumpMin / $baselineMin) * 100, 1) : null,
            'used_l' => round($used, 1),
            'baseline_l' => round($baselineL, 1),
            'saved_l' => $baselineL > 0 ? round($baselineL - $used, 1) : null,
            'parks_measured' => $compared->count(),
            'parks_measured_real' => $compared->filter(fn (Park $p) => $p->source->value === 'real')->count(),
            // One park: why it is not compared (no_pump, manual_irrigation, no_readings), or null.
            'not_compared' => $one === null ? null : ($one['reason'] ?? ($one['measured_s'] <= 0 ? 'no_readings' : (self::enoughData($one) ? null : 'not_enough_data'))),
            'since' => $since ? Carbon::parse($since, 'UTC')->toIso8601ZuluString() : null,
        ];
    }

    /** @param Collection<int, Alert> $alerts @return array<string, mixed> */
    private function alerts(Collection $alerts): array
    {
        $count = fn (string $field, array $cases) => collect($cases)
            ->mapWithKeys(fn ($case) => [$case->value => $alerts->filter(fn (Alert $a) => ($field === 'category' ? $a->type->category() : $a->{$field}) === $case)->count()])
            ->all();

        return [
            'total' => $alerts->count(),
            'by_priority' => $count('priority', TaskPriority::cases()),
            'by_category' => $count('category', AlertCategory::cases()),
            'by_type' => array_filter($count('type', AlertType::cases())),
        ];
    }

    /** @param Collection<int, Task> $tasks @return array<string, mixed> */
    private function tasks(Collection $tasks, Carbon $from, Carbon $to): array
    {
        $in = fn (?Carbon $at) => $at !== null && $at->between($from, $to);
        $done = $tasks->filter(fn (Task $t) => $in($t->done_at) && $t->status === TaskStatus::Done);
        $verified = $done->where('verified', true);
        $checked = $done->filter(fn (Task $t) => $t->verified !== null);
        $minutes = $verified->pluck('time_to_resolve_min')->filter(fn ($m) => $m !== null)->sort()->values();

        return [
            'done' => $done->count(),
            'verified' => $verified->count(),
            'not_verified' => $checked->count() - $verified->count(),
            'pending' => $done->count() - $checked->count(),
            'verified_rate' => $checked->count() > 0 ? round($verified->count() / $checked->count(), 3) : null,
            'median_resolve_min' => $minutes->isEmpty() ? null : (int) round($minutes->median()),
            'escalated' => $tasks->filter(fn (Task $t) => $in($t->escalated_at))->count(),
            'overdue' => $tasks->filter(fn (Task $t) => $in($t->overdue_at))->count(),
            'water_used_l' => round((float) $done->sum('water_used_l'), 1),
        ];
    }
}
