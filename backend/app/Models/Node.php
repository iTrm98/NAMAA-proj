<?php

namespace App\Models;

use App\Enums\DataSource;
use App\Enums\NodeType;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'park_id', 'type', 'name', 'has_pump', 'pump_target', 'dry_value', 'wet_value',
    'last_seen_at', 'detached_at', 'last_moisture_pct', 'last_flow', 'last_temperature',
    'last_humidity', 'last_pump_on', 'last_battery_pct', 'last_litter_count', 'expected_flow_l_min', 'source', 'rssi', 'uptime_s', 'fw_version', 'via', 'capture_mode', 'camera_url',
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
            'last_litter_count' => 'integer',
            'expected_flow_l_min' => 'float',
            'rssi' => 'integer',
            'uptime_s' => 'integer',
            'source' => DataSource::class,
        ];
    }

    /**
     * Real node that has never reported (Sprint 6, B1): not installed yet, so neither offline
     * nor alert-worthy. Every reading sets last_seen_at, so a null value means "no reading yet".
     */
    public function isAwaitingInstall(): bool
    {
        return $this->source === DataSource::Real && $this->last_seen_at === null;
    }

    /** @param Builder<Node> $query */
    public function scopeAwaitingInstall(Builder $query): void
    {
        $query->where('source', DataSource::Real->value)->whereNull('last_seen_at')->whereDoesntHave('readings');
    }

    /** @return BelongsTo<Park, $this> */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** The newest stored reading (hardware page). @return HasOne<Reading, $this> */
    public function latestReading(): HasOne
    {
        return $this->hasOne(Reading::class)->latestOfMany();
    }

    /** @return HasMany<Reading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    /** The newest pump switch (is the pump running because of automatic irrigation?). @return HasOne<PumpLog, $this> */
    public function latestPumpLog(): HasOne
    {
        return $this->hasOne(PumpLog::class)->latestOfMany();
    }

    /** @return HasMany<PumpLog, $this> */
    public function pumpLogs(): HasMany
    {
        return $this->hasMany(PumpLog::class);
    }
}
