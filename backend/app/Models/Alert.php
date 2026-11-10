<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['park_id', 'node_id', 'detection_id', 'type', 'severity', 'priority', 'status', 'task_ready_at', 'data', 'resolved_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'priority' => TaskPriority::class,
            'task_ready_at' => 'datetime',
            'status' => AlertStatus::class,
            'data' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** The camera frame that raised (or last upgraded) a litter alert. @return BelongsTo<Detection, $this> */
    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class);
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** The newest task made from this alert that was not cancelled. @return HasOne<Task, $this> */
    public function latestTask(): HasOne
    {
        return $this->hasOne(Task::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q->where('status', '!=', TaskStatus::Cancelled->value));
    }

    /** Active (open or in-progress) tasks made from this alert. @return HasMany<Task, $this> */
    public function activeTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereIn('status', TaskStatus::active());
    }

    public function isTaskReady(): bool
    {
        return $this->task_ready_at !== null;
    }

    /**
     * Urgent first, then high, normal, low; oldest first within a priority.
     *
     * @param  Builder<Alert>  $query
     */
    public function scopeByPriority(Builder $query): void
    {
        $query->orderByRaw(TaskPriority::orderSql())->orderBy('created_at')->orderBy('id');
    }

    /** @param Builder<Alert> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', AlertStatus::Open);
    }
}
