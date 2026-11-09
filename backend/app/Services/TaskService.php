<?php

namespace App\Services;

use App\Enums\PumpReason;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Events\TaskCreated;
use App\Events\TaskEscalated;
use App\Events\TaskUpdated;
use App\Models\Alert;
use App\Models\Park;
use App\Models\Task;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task life cycle: open -> in_progress -> done -> verified (true / false),
 * or cancelled while open or in progress. Every change is logged (task.*)
 * and broadcast (task.created / task.updated) to the company, municipality
 * and the assigned worker.
 *
 * Response times: due_at = created (or last priority change) + the response
 * time of the priority (TaskPriority::responseSeconds). An open task past
 * due_at is escalated (escalate()). A task follows its alert's priority
 * upwards (followAlert()). Verification runs on a schedule (TaskVerifier).
 */
class TaskService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly PumpService $pump,
    ) {}

    /** @param array{alert_id?: ?int, park_id?: ?int, assigned_to?: ?int, title: string, note?: ?string, priority?: ?string} $data */
    public function create(User $admin, array $data): Task
    {
        return DB::transaction(function () use ($admin, $data) {
            $alert = isset($data['alert_id']) ? Alert::findOrFail($data['alert_id']) : null;
            $park = $alert ? $alert->park : Park::findOrFail($data['park_id']);

            // A task made from an alert takes the alert's current priority by default.
            $priority = isset($data['priority'])
                ? TaskPriority::from($data['priority'])
                : ($alert ? $alert->priority : TaskPriority::Normal);

            $task = Task::create([
                'company_id' => $park->company_id,
                'park_id' => $park->id,
                'alert_id' => $alert?->id,
                'assigned_to' => $data['assigned_to'] ?? null,
                'created_by' => $admin->id,
                'title' => $data['title'],
                'note' => $data['note'] ?? null,
                'priority' => $priority,
                'status' => TaskStatus::Open,
                'due_at' => now()->addSeconds($priority->responseSeconds()),
            ]);

            $this->logger->log('task.created', $task, ['after' => [
                'park_id' => $park->id, 'alert_id' => $alert?->id, 'title' => $task->title, 'priority' => $priority->value,
            ]], user: $admin);

            if ($task->assigned_to) {
                $this->logger->log('task.assigned', $task, ['before' => ['assigned_to' => null], 'after' => ['assigned_to' => $task->assigned_to]], user: $admin);
            }

            Realtime::send(new TaskCreated($task->company_id, self::payload($task), [$task->assigned_to]));

            return $task;
        });
    }

    /** Admin edit: reassign, priority, title, note, or cancel. @param array<string, mixed> $data */
    public function update(Task $task, User $admin, array $data): Task
    {
        return DB::transaction(function () use ($task, $admin, $data) {
            if (($data['status'] ?? null) === TaskStatus::Cancelled->value) {
                $this->cancel($task, $admin);
                unset($data['status']);
            }

            $previousWorker = $task->assigned_to;
            $fields = array_intersect_key($data, array_flip(['assigned_to', 'priority', 'title', 'note']));
            $before = [];
            $after = [];

            foreach ($fields as $key => $value) {
                $current = $task->{$key};
                $current = $current instanceof \BackedEnum ? $current->value : $current;
                if ($current !== $value) {
                    $before[$key] = $current;
                    $after[$key] = $value;
                }
            }

            if ($after === []) {
                return $task;
            }

            $changes = $after;
            if (array_key_exists('priority', $after)) {
                // A new priority starts a new response time from now.
                $changes += [
                    'due_at' => now()->addSeconds(TaskPriority::from($after['priority'])->responseSeconds()),
                    'overdue_at' => null,
                ];
            }
            $task->update($changes);

            if (array_key_exists('assigned_to', $after)) {
                $this->logger->log('task.assigned', $task, [
                    'before' => ['assigned_to' => $before['assigned_to']], 'after' => ['assigned_to' => $after['assigned_to']],
                ], user: $admin);
                unset($before['assigned_to'], $after['assigned_to']);
            }

            if ($after !== []) {
                $this->logger->log('task.updated', $task, ['before' => $before, 'after' => $after], user: $admin);
            }

            $this->broadcastUpdated($task, [$previousWorker]);

            return $task;
        });
    }

    public function cancel(Task $task, ?User $by = null, string $reason = 'admin'): void
    {
        if (! $task->status->isActive()) {
            throw ValidationException::withMessages(['status' => 'Only an open or in-progress task can be cancelled.']);
        }

        $before = $task->status->value;
        $task->update(['status' => TaskStatus::Cancelled]);

        $this->logger->log('task.cancelled', $task, [
            'before' => ['status' => $before],
            'after' => ['status' => 'cancelled', 'reason' => $reason],
        ], user: $by);

        $this->broadcastUpdated($task);
    }

    public function start(Task $task, User $worker): Task
    {
        if ($task->status !== TaskStatus::Open) {
            throw ValidationException::withMessages(['status' => 'Only an open task can be started.']);
        }

        $task->update(['status' => TaskStatus::InProgress, 'started_at' => now()]);
        $this->logger->log('task.started', $task, ['before' => ['status' => 'open'], 'after' => ['status' => 'in_progress']], user: $worker);
        $this->broadcastUpdated($task);

        return $task;
    }

    /** Worker done: note + optional photo; switches the park pump off; verification follows after the delay. */
    public function done(Task $task, User $worker, ?string $note, ?UploadedFile $photo): Task
    {
        if ($task->status !== TaskStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Only a task in progress can be marked done.']);
        }

        $path = $photo?->store('task-photos', 'local');

        DB::transaction(function () use ($task, $worker, $note, $path) {
            $task->update([
                'status' => TaskStatus::Done,
                'done_at' => now(),
                'done_note' => $note,
                'done_photo_path' => $path,
            ]);

            $pumpNode = $task->park->pumpNode()->first();
            if ($pumpNode && $pumpNode->pump_target) {
                $this->pump->set($pumpNode, false, PumpReason::Manual, $worker);
            }

            $this->logger->log('task.done', $task, [
                'before' => ['status' => 'in_progress'],
                'after' => ['status' => 'done', 'note' => $note, 'photo' => $path !== null],
            ], user: $worker);

            // Verification follows on the schedule (namaa:verify-tasks): the alert's
            // condition must stay cleared for a continuous window of readings.
            $this->broadcastUpdated($task);
        });

        return $task;
    }

    /**
     * The alert's priority rose: its active tasks with a lower priority rise
     * with it and get a new due time from now.
     */
    public function followAlert(Alert $alert, string $reason): void
    {
        foreach ($alert->activeTasks()->get() as $task) {
            if (! $alert->priority->isHigherThan($task->priority)) {
                continue;
            }

            $before = $task->priority->value;
            $task->update([
                'priority' => $alert->priority,
                'due_at' => now()->addSeconds($alert->priority->responseSeconds()),
                'overdue_at' => null,
            ]);

            $this->logger->log('task.updated', $task, [
                'before' => ['priority' => $before],
                'after' => ['priority' => $task->priority->value, 'reason' => 'alert_'.$reason],
            ], companyId: $task->company_id);

            $this->broadcastUpdated($task);
        }
    }

    /**
     * An open (not started) task past its due time: one level up with a new
     * due time; an urgent one stays urgent and is flagged overdue (once).
     * The company's admins are notified (task.escalated). In-progress tasks
     * are never escalated (someone is on it). Returns false when nothing changed.
     */
    public function escalate(Task $task): bool
    {
        if ($task->status !== TaskStatus::Open || $task->due_at === null || $task->due_at->isFuture()) {
            return false;
        }

        $before = ['priority' => $task->priority->value];

        if ($task->priority === TaskPriority::Urgent) {
            if ($task->overdue_at !== null) {
                return false;
            }
            $changes = ['overdue_at' => now()];
            $after = ['priority' => 'urgent', 'overdue' => true];
        } else {
            $next = $task->priority->next();
            $changes = ['priority' => $next, 'due_at' => now()->addSeconds($next->responseSeconds())];
            $after = ['priority' => $next->value, 'overdue' => false];
        }

        $task->update($changes + ['escalated_at' => now(), 'escalation_count' => $task->escalation_count + 1]);

        $this->logger->log('task.escalated', $task, ['before' => $before, 'after' => $after + [
            'escalation_count' => $task->escalation_count,
            'due_at' => $task->due_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ]], companyId: $task->company_id);

        Realtime::send(new TaskEscalated($task->company_id, self::payload($task)));
        $this->broadcastUpdated($task);

        return true;
    }

    /** @param list<int|null> $alsoNotify e.g. the previous worker after a reassignment */
    public function broadcastUpdated(Task $task, array $alsoNotify = []): void
    {
        Realtime::send(new TaskUpdated($task->company_id, self::payload($task), [$task->assigned_to, ...$alsoNotify]));
    }

    /** The small task payload of task.created / task.updated. @return array<string, mixed> */
    public static function payload(Task $task): array
    {
        return ['task' => [
            'id' => $task->id,
            'park_id' => $task->park_id,
            'alert_id' => $task->alert_id,
            'assigned_to' => $task->assigned_to,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => $task->due_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'overdue' => $task->isOverdue(),
            'escalated' => $task->escalation_count > 0,
            'verified' => $task->verified,
        ]];
    }
}
