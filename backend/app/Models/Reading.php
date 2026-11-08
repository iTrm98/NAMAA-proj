<?php

namespace App\Models;

use App\Enums\DataSource;
use Database\Factories\ReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'node_id', 'park_id', 'moisture_raw', 'moisture_pct', 'flow', 'temperature',
    'humidity', 'pump_on', 'water_level', 'source', 'created_at',
])]
class Reading extends Model
{
    /** @use HasFactory<ReadingFactory> */
    use HasFactory, MassPrunable;

    /** Append-only: created_at only. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'moisture_raw' => 'integer',
            'moisture_pct' => 'float',
            'flow' => 'float',
            'temperature' => 'float',
            'humidity' => 'float',
            'pump_on' => 'boolean',
            'water_level' => 'integer',
            'source' => DataSource::class,
            'created_at' => 'datetime',
        ];
    }

    /** Simulated readings older than 30 days are pruned (scheduled later). */
    public function prunable(): Builder
    {
        return static::query()
            ->where('source', DataSource::Simulated)
            ->where('created_at', '<', now()->subDays(30));
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }
}
