<?php

namespace App\Models;

use Database\Factories\ParkClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sourced claim about a park (area, opening year, status). Conflicting values are separate rows. */
#[Fillable(['park_id', 'field', 'value', 'source_name', 'source_url', 'source_date', 'note', 'created_by'])]
class ParkClaim extends Model
{
    /** @use HasFactory<ParkClaimFactory> */
    use HasFactory;

    public const FIELDS = ['total_area_m2', 'green_area_m2', 'opened_year', 'status', 'other'];

    protected function casts(): array
    {
        return ['source_date' => 'date'];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
