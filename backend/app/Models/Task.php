<?php

namespace App\Models;

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
    'verified', 'verified_at', 'time_to_resolve_min', 'water_used_l',
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
