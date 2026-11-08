<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['park_id', 'node_id', 'type', 'severity', 'status', 'data', 'resolved_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'data' => 'array',
            'resolved_at' => 'datetime',
        ];
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

    /** @param Builder<Alert> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', AlertStatus::Open);
    }
}
