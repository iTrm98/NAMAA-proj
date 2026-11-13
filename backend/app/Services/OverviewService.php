<?php

namespace App\Services;

use App\Enums\AlertCategory;
use App\Enums\AlertType;
use App\Enums\SiteType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Task;
use App\Enums\NodeType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The overview page in one payload (Sprint 6, B2): KPIs, the "right now" strip
 * and the needs-attention lists, for a set of parks (the admin's company, or
 * every park for the municipality). Fixed number of queries: open alerts,
 * in-progress tasks, tasks done in the period and awaiting nodes.
 */
class OverviewService
{
    public const DAYS = 30;

    private const LIMIT = 10;

    /**
     * @param  Collection<int, Park>  $parks  with `nodes` (and `company` when $withCompany) loaded, test parks left out
     * @return array<string, mixed>
     */
    public function build(Collection $parks, bool $withCompany = false): array
    {
        $byId = $parks->keyBy('id');
        $ids = $byId->keys()->all();
        $park = fn (int $id) => $this->parkRow($byId[$id], $withCompany);

        $alerts = Alert::open()->whereIn('park_id', $ids)->with(['latestTask.assignee'])->byPriority()->get();
        $active = Task::whereIn('park_id', $ids)->where('status', TaskStatus::InProgress)
            ->with(['alert.node', 'assignee'])->byPriority()->limit(self::LIMIT)->get();
        $done = Task::whereIn('park_id', $ids)->where('status', TaskStatus::Done)
            ->where('done_at', '>=', now()->subDays(self::DAYS))
            ->get(['id', 'verified', 'time_to_resolve_min']);
        $awaiting = Node::awaitingInstall()->whereNull('detached_at')->whereIn('park_id', $ids)
            // Placeholder nodes (flag set by stand:setup, any name) and stand boards never ask for an installation.
            ->when(Schema::hasColumn('nodes', 'placeholder'), fn ($q) => $q->where('placeholder', false))
            ->whereDoesntHave('park', fn ($q) => $q->where('site_type', SiteType::DemoStand->value))
            ->orderBy('id')->get(['id', 'park_id', 'name', 'type']);

        $count = fn (array $types) => $alerts->filter(fn (Alert $a) => in_array($a->type, $types, true));

        $verified = $done->where('verified', true);
        $failed = $done->filter(fn (Task $t) => $t->verified === false);
        $decided = $verified->count() + $failed->count();
        $minutes = $verified->pluck('time_to_resolve_min')->filter(fn ($m) => $m !== null)->sort()->values();

        $data = [
            'kpis' => [
                'parks' => [
                    'total' => $parks->count(),
                    'real' => $parks->filter(fn (Park $p) => $p->source->value === 'real')->count(),
                    'simulated' => $parks->filter(fn (Park $p) => $p->source->value === 'simulated')->count(),
                ],
                'open_alerts' => [
                    'total' => $alerts->count(),
                    'by_priority' => collect(TaskPriority::cases())
                        ->mapWithKeys(fn (TaskPriority $p) => [$p->value => $alerts->where('priority', $p)->count()])->all(),
                ],
                'fixes' => [
                    'days' => self::DAYS,
                    'done' => $done->count(),
                    'verified' => $verified->count(),
                    'not_verified' => $failed->count(),
                    'pending' => $done->count() - $decided,
                    'verified_pct' => $decided > 0 ? round($verified->count() / $decided * 100, 1) : null,
                ],
                'median_fix_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->median()),
            ],
            'alerts_by_category' => $this->alertsByCategory($ids, $awaiting->pluck('id')->all()),
            'right_now' => [
                'parks_needing_water' => $count([AlertType::Dry, AlertType::HeatStress])->pluck('park_id')->unique()->count(),
                'open_leaks' => $count([AlertType::Leak])->count(),
                'open_litter' => $count([AlertType::Litter, AlertType::LitterRepeated])->count(),
                'sensors_awaiting_installation' => $awaiting->count(),
            ],
            'needs_attention' => [
                // Uncapped totals behind the three lists (the lists show at most 10 each).
                'counts' => [
                    'alerts' => $alerts->count(),
                    'tasks_in_progress' => Task::whereIn('park_id', $ids)->where('status', TaskStatus::InProgress)->count(),
                    'setup' => $awaiting->count() + $parks->filter(fn (Park $p) => $p->outline_source === 'approximate')->count(),
                ],
                'alerts' => $alerts->take(self::LIMIT)->map(fn (Alert $a) => [
                    'id' => $a->id,
                    'park' => $park($a->park_id),
                    'type' => $a->type->value,
                    'category' => $a->type->category()->value,
                    'priority' => $a->priority->value,
                    'task_ready' => $a->isTaskReady(),
                    'created_at' => $a->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    'task' => $a->latestTask ? [
                        'id' => $a->latestTask->id,
                        'status' => $a->latestTask->status->value,
                        'assigned_to' => $this->person($a->latestTask->assignee),
                    ] : null,
                ])->values()->all(),
                'tasks_in_progress' => $active->map(fn (Task $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'title_key' => $t->title_key,
                    'title_park_id' => $t->title_park_id,
                    'park' => $park($t->park_id),
                    'priority' => $t->priority->value,
                    'assigned_to' => $this->person($t->assignee),
                    'started_at' => $t->started_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'watering' => $this->watering($t, $byId[$t->park_id]),
                ])->values()->all(),
                'setup' => $awaiting->map(fn (Node $n) => [
                    'type' => 'awaiting_installation',
                    'park' => $park($n->park_id),
                    'node' => ['id' => $n->id, 'name' => $n->name, 'type' => $n->type->value],
                ])->concat(
                    $parks->filter(fn (Park $p) => $p->outline_source === 'approximate')->map(fn (Park $p) => [
                        'type' => 'outline_unverified', 'park' => $park($p->id), 'node' => null,
                    ])
                )->values()->all(),
            ],
        ];

        if ($withCompany) {
            $data['by_company'] = $parks->groupBy('company_id')->map(function (Collection $group) use ($alerts) {
                $mine = $alerts->whereIn('park_id', $group->pluck('id'));

                return [
                    'company' => ['id' => $group->first()->company->id, 'name' => $group->first()->company->name],
                    'parks' => $group->count(),
                    'open_alerts' => $mine->count(),
                    'urgent_alerts' => $mine->where('priority', TaskPriority::Urgent)->count(),
                ];
            })->values()->all();
        }

        return $data;
    }

    /**
     * Alerts raised in the last 30 days per category, leaving out alerts of nodes
     * awaiting installation and alerts resolved with reason `awaiting_install`.
     *
     * @param  array<int, int>  $parkIds
     * @param  array<int, int>  $awaitingNodeIds
     * @return array<string, int>
     */
    private function alertsByCategory(array $parkIds, array $awaitingNodeIds): array
    {
        $alerts = Alert::whereIn('park_id', $parkIds)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->when($awaitingNodeIds !== [], fn ($q) => $q->where(fn ($q) => $q->whereNull('node_id')->orWhereNotIn('node_id', $awaitingNodeIds)))
            ->get(['id', 'type']);

        $cleanup = ActivityLog::where('action', 'alert.resolved')
            ->where('subject_type', (new Alert)->getMorphClass())
            ->whereIn('subject_id', $alerts->pluck('id'))
            ->get(['subject_id', 'diff'])
            ->filter(fn (ActivityLog $l) => ($l->diff['after']['reason'] ?? null) === 'awaiting_install')
            ->pluck('subject_id');

        $alerts = $alerts->reject(fn (Alert $a) => $cleanup->contains($a->id));

        return collect(AlertCategory::cases())
            ->mapWithKeys(fn (AlertCategory $c) => [$c->value => $alerts->filter(fn (Alert $a) => $a->type->category() === $c)->count()])
            ->all();
    }

    /**
     * The demo stand block of the admin overview: the stand park, whether any of its boards
     * reported within the offline window, and the latest values of its sensor board.
     *
     * @return array<string, mixed>|null
     */
    public function demoStand(int $companyId): ?array
    {
        $stand = StandSetup::find($companyId);
        if (! $stand) {
            return null;
        }

        $nodes = Node::where('park_id', $stand->id)->whereNull('detached_at')->get();
        $sensor = $nodes->where('type', NodeType::Sensor)->sortByDesc('last_seen_at')->first();
        $online = $nodes->contains(fn (Node $n) => $n->last_seen_at !== null
            && $n->last_seen_at->gte(now()->subSeconds(OfflineChecker::offlineSeconds($n->type))));

        return [
            'park_id' => $stand->id,
            'name_ar' => $stand->name_ar,
            'name_en' => $stand->name_en,
            'online' => $online,
            'latest' => $sensor?->last_seen_at ? [
                'moisture_pct' => $sensor->last_moisture_pct,
                'temperature_c' => $sensor->last_temperature,
                'humidity_pct' => $sensor->last_humidity,
                'at' => $sensor->last_seen_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function parkRow(Park $park, bool $withCompany): array
    {
        return [
            'id' => $park->id,
            'name_ar' => $park->name_ar,
            'name_en' => $park->name_en,
            'source' => $park->source->value,
        ] + ($withCompany ? ['company' => ['id' => $park->company->id, 'name' => $park->company->name]] : []);
    }

    /** @return array<string, mixed>|null */
    private function person(?\App\Models\User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'name_ar' => $user->name_ar] : null;
    }

    /** Watering progress of a dry / heat-stress task, else null. @return array<string, mixed>|null */
    private function watering(Task $task, Park $park): ?array
    {
        $task->setRelation('park', $park);
        $target = $task->wateringTarget();

        if ($target === null) {
            return null;
        }

        $current = $task->alert->node?->last_moisture_pct
            ?? $park->nodes->first(fn (Node $n) => $n->type->value === 'sensor')?->last_moisture_pct;
        $start = $task->alert->data['moisture_pct'] ?? null;
        $progress = null;

        if ($current !== null && $start !== null && $target > $start) {
            $progress = (int) max(0, min(100, round(($current - $start) / ($target - $start) * 100)));
        } elseif ($current !== null && $current >= $target) {
            $progress = 100;
        }

        return [
            'start_pct' => $start !== null ? (float) $start : null,
            'current_pct' => $current !== null ? (float) $current : null,
            'target_pct' => (float) $target,
            'progress_pct' => $progress,
        ];
    }
}
