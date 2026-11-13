<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The 30-day composite of one park: the satellite headline (data-model.md, Sprint 6.2). */
class NdviComposite extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'date_from' => 'date',
            'date_to' => 'date',
            'value' => 'float',
            'mean' => 'float',
            'p90' => 'float',
            'green_cover_pct' => 'float',
            'clear_fraction' => 'float',
            'consistency_mad' => 'float',
            'usual_pixels' => 'float',
            'inner_width_m' => 'float',
            'baseline_median' => 'float',
            'baseline_mad' => 'float',
            'z' => 'float',
            'rel_drop' => 'float',
            'confidence' => 'integer',
            'dates_count' => 'integer',
            'pure_pixels' => 'integer',
            'baseline_n' => 'integer',
            'reasons' => 'array',
            'flags' => 'array',
        ];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }
}
