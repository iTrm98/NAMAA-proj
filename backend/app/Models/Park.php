<?php

namespace App\Models;

use App\Enums\AlertStatus;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\ParkType;
use Database\Factories\ParkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id', 'name_ar', 'name_en', 'district', 'park_type', 'area_m2',
    'polygon', 'lat', 'lng', 'moisture_min', 'moisture_max', 'baseline_l_per_day',
    'status', 'status_changed_at', 'last_reading_at', 'source',
])]
class Park extends Model
{
    /** @use HasFactory<ParkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'park_type' => ParkType::class,
            'polygon' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'moisture_min' => 'integer',
            'moisture_max' => 'integer',
            'area_m2' => 'integer',
            'baseline_l_per_day' => 'integer',
            'status' => ParkStatus::class,
            'status_changed_at' => 'datetime',
            'last_reading_at' => 'datetime',
            'source' => DataSource::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** First sensor node of the park. @return HasOne<Node, $this> */
    public function sensorNode(): HasOne
    {
        return $this->hasOne(Node::class)->where('type', NodeType::Sensor)->orderBy('id');
    }

    /** First camera node of the park. @return HasOne<Node, $this> */
    public function cameraNode(): HasOne
    {
        return $this->hasOne(Node::class)->where('type', NodeType::Camera)->orderBy('id');
    }

    /** The node that switches the park's pump (has_pump = true). @return HasOne<Node, $this> */
    public function pumpNode(): HasOne
    {
        return $this->hasOne(Node::class)->where('has_pump', true)->orderBy('id');
    }

    /** @return HasMany<Reading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /** @return HasMany<Task, $this> */
    /** Open alerts, newest first. @return HasMany<Alert, $this> */
    public function openAlerts(): HasMany
    {
        return $this->hasMany(Alert::class)->where('status', AlertStatus::Open)->latest()->orderByDesc('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Detection, $this> */
    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }

    /** @return HasMany<NdviReading, $this> */
    public function ndviReadings(): HasMany
    {
        return $this->hasMany(NdviReading::class);
    }

    /** @param Builder<Park> $query */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }
}
