<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An alert (api.md, GET /api/admin/alerts). Needs `park` and `latestTask.assignee`
 * loaded. `task` is the newest task made from it that was not cancelled, so the
 * dashboard can show "Task assigned to Ahmed".
 *
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    use FormatsTimes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $task = $this->relationLoaded('latestTask') ? $this->latestTask : null;

        return [
            'id' => $this->id,
            'park_id' => $this->park_id,
            'park' => $this->whenLoaded('park', fn () => [
                'id' => $this->park->id,
                'name_ar' => $this->park->name_ar,
                'name_en' => $this->park->name_en,
                'source' => $this->park->source->value,
                'auto_irrigation' => $this->park->auto_irrigation,
            ]),
            'node_id' => $this->node_id,
            'type' => $this->type->value,
            'category' => $this->type->category()->value,
            'priority' => $this->priority->value,
            'task_ready' => $this->isTaskReady(),
            'task_ready_at' => $this->iso($this->task_ready_at),
            'message' => 'alert.'.$this->type->value,
            'data' => $this->data,
            'recommended_action' => 'action.'.$this->type->value,
            'status' => $this->status->value,
            'task' => $task ? [
                'id' => $task->id,
                'status' => $task->status->value,
                'priority' => $task->priority->value,
                'due_at' => $this->iso($task->due_at),
                'overdue' => $task->isOverdue(),
                'verified' => $task->verified,
                'assigned_to' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->name] : null,
            ] : null,
            'created_at' => $this->iso($this->created_at),
            'resolved_at' => $this->iso($this->resolved_at),
        ];
    }
}
