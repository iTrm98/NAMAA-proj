<?php

namespace App\Models;

use Database\Factories\NdviReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['park_id', 'date', 'mean', 'pixels'])]
class NdviReading extends Model
{
    /** @use HasFactory<NdviReadingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'mean' => 'float',
            'pixels' => 'integer',
        ];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }
}
