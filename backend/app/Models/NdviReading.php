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

    /** An outline that covers fewer valid satellite pixels than this cannot be measured reliably. */
    public function tooSmall(): bool
    {
        return $this->pixels < (int) config('namaa.ndvi.min_pixels');
    }

    /**
     * The shape used in park and impact responses: `mean` is null when the outline is too small.
     *
     * @return array{mean: ?float, date: string, pixels: int, too_small: bool}
     */
    public function summary(): array
    {
        $small = $this->tooSmall();

        return ['mean' => $small ? null : $this->mean, 'date' => $this->date->toDateString(), 'pixels' => $this->pixels, 'too_small' => $small];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }
}
