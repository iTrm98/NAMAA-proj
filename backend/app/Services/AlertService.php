<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Events\AlertRaised;
use App\Events\AlertResolved;
use App\Events\AlertUpdated;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Support\Realtime;

/**
 * Raises, updates and resolves alerts. Every alert has a category (from its
 * type) and a stored priority (base priority from the type; it can rise).
 * Every raise, priority change, conversion, task-ready and resolve is written
 * to activity_logs and broadcast (alert.raised / alert.updated / alert.resolved).
 * When an alert's priority rises, its active task rises with it.
 */
class AlertService
{
    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly TaskService $tasks,
    ) {}

    /** @param array<string, mixed> $data */
    public function raise(Park $park, ?Node $node, AlertType $type, AlertSeverity $severity, array $data, ?TaskPriority $priority = null): Alert
    {
        $priority ??= $type->priority();

        $alert = Alert::create([
            'park_id' => $park->id,
            'node_id' => $node?->id,
            'type' => $type,
            'severity' => $severity,
            'priority' => $priority,
            'status' => AlertStatus::Open,
            'task_ready_at' => $type->taskReadyAtOnce() ? now() : null,
            'data' => $data,
        ]);

        $this->logger->log('alert.raised', $alert, ['after' => [
            'type' => $type->value, 'priority' => $priority->value, 'severity' => $severity->value,
            'node_id' => $node?->id, 'data' => $data,
        ]], companyId: $park->company_id);

        Realtime::send(new AlertRaised($park->company_id, ['alert' => self::payload($alert)]));

        return $alert;
    }

    /**
     * A repeat of an open alert's condition: refresh its data, keep created_at.
     *
     * @param  array<string, mixed>  $data
     */
    public function touch(Alert $alert, array $data): void
    {
        $alert->update(['data' => $data]);
    }

    /** Raise the priority (never lowers). Returns true when it changed. */
    public function raisePriority(Alert $alert, TaskPriority $priority, string $reason): bool
    {
        if (! $priority->isHigherThan($alert->priority)) {
            return false;
        }

        $before = $alert->priority->value;
        $alert->update(['priority' => $priority]);

        $this->logger->log('alert.escalated', $alert, [
            'before' => ['priority' => $before],
            'after' => ['priority' => $priority->value, 'reason' => $reason],
        ], companyId: $alert->park->company_id);

        $this->broadcastUpdated($alert);
        $this->tasks->followAlert($alert, $reason);

        return true;
    }

    /**
     * Convert an open alert in place to another type (one alert per problem),
     * e.g. dry -> heat_stress. Priority and severity follow the new type
     * (the priority never goes down).
     *
     * @param  array<string, mixed>  $data
     */
    public function convert(Alert $alert, AlertType $type, string $reason, array $data): void
    {
        $before = ['type' => $alert->type->value, 'priority' => $alert->priority->value];
        $priority = $type->priority()->isHigherThan($alert->priority) ? $type->priority() : $alert->priority;

        $alert->update([
            'type' => $type,
            'severity' => $type->severity(),
            'priority' => $priority,
            'data' => $data,
        ]);

        $this->logger->log('alert.escalated', $alert, [
            'before' => $before,
            'after' => ['type' => $type->value, 'priority' => $priority->value, 'reason' => $reason],
        ], companyId: $alert->park->company_id);

        $this->broadcastUpdated($alert);
        $this->tasks->followAlert($alert, $reason);
    }

    /** An alert that had to wait (offline) can now get a task. */
    public function markTaskReady(Alert $alert): void
    {
        if ($alert->isTaskReady()) {
            return;
        }

        $alert->update(['task_ready_at' => now()]);

        $this->logger->log('alert.task_ready', $alert, ['after' => ['task_ready' => true]], companyId: $alert->park->company_id);

        $this->broadcastUpdated($alert);
    }

    public function resolve(Alert $alert, string $reason = 'condition_cleared'): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);
        $park = $alert->park;

        $this->logger->log('alert.resolved', $alert, [
            'before' => ['status' => 'open'],
            'after' => ['status' => 'resolved', 'reason' => $reason],
        ], companyId: $park->company_id);

        Realtime::send(new AlertResolved($park->company_id, [
            'alert_id' => $alert->id,
            'park_id' => $alert->park_id,
            'type' => $alert->type->value,
        ]));
    }

    private function broadcastUpdated(Alert $alert): void
    {
        Realtime::send(new AlertUpdated($alert->park->company_id, ['alert' => self::payload($alert)]));
    }

    /** The small alert payload of alert.raised / alert.updated. @return array<string, mixed> */
    public static function payload(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'park_id' => $alert->park_id,
            'node_id' => $alert->node_id,
            'type' => $alert->type->value,
            'category' => $alert->type->category()->value,
            'priority' => $alert->priority->value,
            'task_ready' => $alert->isTaskReady(),
            'data' => $alert->data,
        ];
    }
}
