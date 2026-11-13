<?php

namespace App\Models;

use Database\Factories\NdviReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'park_id', 'date', 'mean', 'pixels', 'median', 'p90', 'pure_pixels', 'weighted_pixels', 'clear_pixels',
    'usual_pixels', 'clear_fraction', 'green_cover_pct', 'measured_on', 'method', 'confidence', 'level', 'reasons',
])]
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
            'median' => 'float',
            'p90' => 'float',
            'pure_pixels' => 'integer',
            'weighted_pixels' => 'float',
            'clear_pixels' => 'float',
            'usual_pixels' => 'float',
            'clear_fraction' => 'float',
            'green_cover_pct' => 'float',
            'confidence' => 'integer',
            'reasons' => 'array',
        ];
    }

    /** True once migration 2026_11_21_000001 has run (guards the v2 code until then). */
    public static function hasV2Columns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('ndvi_readings', 'method');
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
