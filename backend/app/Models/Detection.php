<?php

namespace App\Models;

use Database\Factories\DetectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'park_id', 'node_id', 'photo_path', 'count', 'boxes', 'items', 'people_count', 'model',
    'inference_ms', 'image_w', 'image_h', 'origin', 'captured_at',
])]
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
            'items' => 'array',
            'people_count' => 'integer',
            'inference_ms' => 'integer',
            'image_w' => 'integer',
            'image_h' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** Litter was seen in this frame. */
    public function hasLitter(): bool
    {
        return $this->count > 0;
    }

    /** API path of the stored (blurred) photo; the dashboard fetches it with the Bearer token. */
    public function imagePath(string $prefix = 'admin'): string
    {
        return "/{$prefix}/detections/{$this->id}/image";
    }

    /** The camera node that took the photo. @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
