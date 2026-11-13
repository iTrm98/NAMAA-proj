<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The pixels of the newest composite, as GeoJSON squares for the map. */
class NdviGrid extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'pixels' => 'integer', 'geojson' => 'array'];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }
}
