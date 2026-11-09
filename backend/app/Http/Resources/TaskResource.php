<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsTimes;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task (api.md, Tasks). `park`, `alert`, `assignee` and `creator` are added
 * when loaded. The photo is never a public URL: `has_photo` tells the
 * dashboard to fetch it from the authenticated photo route.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    use FormatsTimes;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'park_id' => $this->park_id,
            'park' => $this->whenLoaded('park', fn () => [
                'id' => $this->park->id,
                'name_ar' => $this->park->name_ar,
                'name_en' => $this->park->name_en,
                'district' => $this->park->district,
                'status' => $this->park->status->value,
                'source' => $this->park->source->value,
            ]),
            'alert_id' => $this->alert_id,
            'alert' => $this->whenLoaded('alert', fn () => $this->alert ? [
                'id' => $this->alert->id,
                'type' => $this->alert->type->value,
                'category' => $this->alert->type->category()->value,
                'priority' => $this->alert->priority->value,
                'status' => $this->alert->status->value,
                'data' => $this->alert->data,
                'recommended_action' => 'action.'.$this->alert->type->value,
                'created_at' => $this->iso($this->alert->created_at),
            ] : null),
            'title' => $this->title,
            'note' => $this->note,
            // The task's category is its alert's (null for a task made from a park).
            'category' => $this->whenLoaded('alert', fn () => $this->alert?->type->category()->value),
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'due_at' => $this->iso($this->due_at),
            'overdue' => $this->isOverdue(),
            'escalated_at' => $this->iso($this->escalated_at),
            'escalation_count' => $this->escalation_count ?? 0,
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->person($this->assignee)),
            'created_by' => $this->whenLoaded('creator', fn () => $this->person($this->creator)),
            'created_at' => $this->iso($this->created_at),
            'started_at' => $this->iso($this->started_at),
            'done_at' => $this->iso($this->done_at),
            'done_note' => $this->done_note,
            'has_photo' => $this->done_photo_path !== null,
            'verified' => $this->verified,
            'verified_at' => $this->iso($this->verified_at),
            'verify_reason' => $this->verify_reason,
            'time_to_resolve_minutes' => $this->time_to_resolve_min,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
