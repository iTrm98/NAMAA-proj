<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\Task;
use App\Models\User;

/**
 * The chain of a park's latest problem (Sprint 6, B3), built from the activity
 * logs and pump logs: alert raised -> task created -> assigned -> started ->
 * pump on / off -> done (with note) -> verified or not verified. Read-only.
 */
class TimelineService
{
    /** @return array<string, mixed>|null null when the park has no alert */
    public function forPark(Park $park, ?int $alertId = null): ?array
    {
        $alert = $alertId !== null
            ? Alert::where('park_id', $park->id)->findOrFail($alertId)
            : $this->latestAlert($park);

        if (! $alert) {
            return null;
        }

        $task = Task::where('alert_id', $alert->id)->where('status', '!=', TaskStatus::Cancelled->value)
            ->with('assignee')->orderByDesc('id')->first();
        // A cancelled task still tells the story when nothing replaced it.
        $cancelled = $task ? null : Task::where('alert_id', $alert->id)->orderByDesc('id')->first();
        $subjectTask = $task ?? $cancelled;

        $logs = ActivityLog::query()
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('subject_type', $alert->getMorphClass())->where('subject_id', $alert->id))
                ->when($subjectTask, fn ($q2) => $q2->orWhere(fn ($t) => $t->where('subject_type', $subjectTask->getMorphClass())->where('subject_id', $subjectTask->id))))
            ->whereIn('action', ['alert.raised', 'alert.resolved', 'task.created', 'task.assigned', 'task.started', 'task.done', 'task.verified', 'task.cancelled'])
            ->with('user')->orderBy('created_at')->orderBy('id')->get();

        $workers = User::whereIn('id', $logs->filter(fn ($l) => $l->action === 'task.assigned')->map(fn ($l) => $l->diff['after']['assigned_to'] ?? null)->filter()->unique())
            ->get()->keyBy('id');

        $steps = [];
        $verified = false;

        foreach ($logs as $log) {
            $after = $log->diff['after'] ?? [];
            $at = $log->created_at->utc()->format('Y-m-d\TH:i:s\Z');
            $actor = $this->person($log->user);

            $step = match ($log->action) {
                'alert.raised' => ['alert_raised', ['type' => $after['type'] ?? $alert->type->value, 'priority' => $after['priority'] ?? $alert->priority->value]],
                'task.created' => ['task_created', ['priority' => $after['priority'] ?? null]],
                'task.assigned' => empty($after['assigned_to']) ? null : ['task_assigned', ['assigned_to' => $this->person($workers->get($after['assigned_to']))]],
                'task.started' => ['task_started', []],
                'task.done' => ['task_done', [
                    'note' => $after['note'] ?? null,
                    'moisture_pct' => $after['moisture_pct'] ?? null,
                    'below_target' => $after['done_below_target'] ?? null,
                ]],
                'task.verified' => ($after['verified'] ?? false)
                    ? ['verified', ['minutes' => $after['time_to_resolve_min'] ?? null]]
                    : ['not_verified', ['reason' => $after['reason'] ?? null]],
                'task.cancelled' => ['task_cancelled', []],
                'alert.resolved' => ['alert_resolved', ['reason' => $after['reason'] ?? null]],
                default => null,
            };

            if ($step === null) {
                continue;
            }

            if ($log->action === 'task.verified' && ($after['verified'] ?? false)) {
                $verified = true;
            }

            $steps[] = ['key' => $step[0], 'at' => $at, 'actor' => $actor, 'data' => $step[1], '_ts' => $log->created_at];
        }

        // An alert resolved by a verification is already told by the "verified" step.
        if ($verified) {
            $steps = array_values(array_filter($steps, fn ($s) => $s['key'] !== 'alert_resolved'));
        }

        $steps = array_merge($steps, $this->pumpSteps($park, $subjectTask));
        usort($steps, fn ($a, $b) => $a['_ts'] <=> $b['_ts']);
        $steps = array_map(function ($s) {
            unset($s['_ts']);

            return $s;
        }, $steps);

        return [
            'alert' => [
                'id' => $alert->id,
                'type' => $alert->type->value,
                'category' => $alert->type->category()->value,
                'priority' => $alert->priority->value,
                'status' => $alert->status->value,
                'created_at' => $alert->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'resolved_at' => $alert->resolved_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'task' => $subjectTask ? [
                'id' => $subjectTask->id,
                'title' => $subjectTask->title,
                'title_key' => $subjectTask->title_key,
                'title_park_id' => $subjectTask->title_park_id,
                'status' => $subjectTask->status->value,
                'verified' => $subjectTask->verified,
                'assigned_to' => $this->person($subjectTask->assignee),
            ] : null,
            'steps' => $steps,
            'duration_minutes' => $subjectTask?->verified === true ? $subjectTask->time_to_resolve_min : null,
        ];
    }

    /** Newest alert with a non-cancelled task, else the newest alert; alerts resolved as `awaiting_install` are skipped. */
    private function latestAlert(Park $park): ?Alert
    {
        $alerts = Alert::where('park_id', $park->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        $cleanup = ActivityLog::where('action', 'alert.resolved')
            ->where('subject_type', (new Alert)->getMorphClass())
            ->whereIn('subject_id', $alerts->pluck('id'))
            ->get(['subject_id', 'diff'])
            ->filter(fn (ActivityLog $l) => ($l->diff['after']['reason'] ?? null) === 'awaiting_install')
            ->pluck('subject_id');

        $alerts = $alerts->reject(fn (Alert $a) => $cleanup->contains($a->id))->values();

        $withTask = Task::whereIn('alert_id', $alerts->pluck('id'))->where('status', '!=', TaskStatus::Cancelled->value)
            ->pluck('alert_id');

        return $alerts->first(fn (Alert $a) => $withTask->contains($a->id)) ?? $alerts->first();
    }

    /**
     * The park's pump switches from the task's start to its end (verification, else done, else now).
     *
     * @return list<array<string, mixed>>
     */
    private function pumpSteps(Park $park, ?Task $task): array
    {
        $pump = $park->pumpNode()->first(['id']);

        if (! $pump || ! $task?->started_at) {
            return [];
        }

        $to = $task->verified_at ?? $task->done_at ?? now();

        return PumpLog::where('node_id', $pump->id)->whereBetween('created_at', [$task->started_at, $to])
            ->with('user')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (PumpLog $l) => [
                'key' => $l->target ? 'pump_on' : 'pump_off',
                'at' => $l->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'actor' => $this->person($l->user),
                'data' => ['reason' => $l->reason->value],
                '_ts' => $l->created_at,
            ])->all();
    }

    /** @return array<string, mixed>|null */
    private function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'name_ar' => $user->name_ar] : null;
    }
}
