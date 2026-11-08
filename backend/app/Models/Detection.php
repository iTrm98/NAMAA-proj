<?php

namespace App\Models;

use Database\Factories\DetectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['park_id', 'node_id', 'photo_path', 'count', 'boxes', 'captured_at'])]
class Detection extends Model
{
    /** @use HasFactory<DetectionFactory> */
    use HasFactory;

    /** The table has captured_at only, no created_at / updated_at. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'boxes' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** The camera node that took the photo. @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
