<?php

namespace App\Models;

use App\Enums\PumpReason;
use Database\Factories\PumpLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['node_id', 'user_id', 'target', 'reason', 'created_at'])]
class PumpLog extends Model
{
    /** @use HasFactory<PumpLogFactory> */
    use HasFactory;

    /** Append-only: created_at only. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'target' => 'boolean',
            'reason' => PumpReason::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** null = the system switched it. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
