<?php

namespace App\Models;

use App\Enums\DataSource;
use App\Enums\NodeType;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'park_id', 'type', 'name', 'has_pump', 'pump_target', 'dry_value', 'wet_value',
    'last_seen_at', 'last_moisture_pct', 'last_flow', 'last_temperature',
    'last_humidity', 'last_pump_on', 'last_battery_pct', 'expected_flow_l_min', 'source',
])]
class Node extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasApiTokens, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => NodeType::class,
            'has_pump' => 'boolean',
            'pump_target' => 'boolean',
            'dry_value' => 'integer',
            'wet_value' => 'integer',
            'last_seen_at' => 'datetime',
            'last_moisture_pct' => 'float',
            'last_flow' => 'float',
            'last_temperature' => 'float',
            'last_humidity' => 'float',
            'last_pump_on' => 'boolean',
            'last_battery_pct' => 'float',
            'expected_flow_l_min' => 'float',
            'source' => DataSource::class,
        ];
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** @return HasMany<Reading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    /** @return HasMany<PumpLog, $this> */
    public function pumpLogs(): HasMany
    {
        return $this->hasMany(PumpLog::class);
    }
}
