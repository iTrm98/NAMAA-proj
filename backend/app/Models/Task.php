<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'park_id', 'alert_id', 'assigned_to', 'created_by', 'title',
    'priority', 'status', 'started_at', 'done_at', 'note', 'photo_path',
    'done_note', 'done_photo_path', 'verified', 'verified_at', 'verify_reason',
    'time_to_resolve_min', 'water_used_l', 'due_at', 'escalated_at', 'escalation_count', 'overdue_at',
    'done_moisture_pct', 'done_below_target',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'started_at' => 'datetime',
            'done_at' => 'datetime',
            'verified' => 'boolean',
            'verified_at' => 'datetime',
            'time_to_resolve_min' => 'integer',
            'water_used_l' => 'float',
            'done_moisture_pct' => 'float',
            'done_below_target' => 'boolean',
            'due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'escalation_count' => 'integer',
            'overdue_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** @return BelongsTo<Alert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /** The worker the task is assigned to. @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The admin who created the task. @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Task> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::active());
    }

    /**
     * Urgent first, then high, normal, low; oldest first within a priority.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeByPriority(Builder $query): void
    {
        $query->orderByRaw(TaskPriority::orderSql())
            ->orderBy('created_at')->orderBy('id');
    }

    /** Active and past its due time. */
    /**
     * Watering target of a dry / heat-stress task: the middle of the park's
     * range, where automatic irrigation stops. Null for other tasks.
     * Needs `alert` and `park` (loaded or lazily).
     */
    public function wateringTarget(): ?float
    {
        $type = $this->alert?->type;

        return in_array($type, [AlertType::Dry, AlertType::HeatStress], true) ? $this->park->moistureMid() : null;
    }

    public function isOverdue(): bool
    {
        return $this->status->isActive() && $this->due_at !== null && $this->due_at->isPast();
    }

    /** @param Builder<Task> $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereIn('status', TaskStatus::active())->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /** @param Builder<Task> $query */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    /** @param Builder<Task> $query */
    public function scopeForWorker(Builder $query, int $userId): void
    {
        $query->where('assigned_to', $userId);
    }

    /** @param Builder<Task> $query */
    public function scopeStatus(Builder $query, TaskStatus|string $status): void
    {
        $query->where('status', $status instanceof TaskStatus ? $status->value : $status);
    }
}
