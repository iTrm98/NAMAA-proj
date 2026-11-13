<?php

namespace App\Models;

use App\Enums\AlertStatus;
use App\Enums\DataSource;
use App\Enums\GeometrySource;
use App\Enums\MonitoringMode;
use App\Enums\NodeType;
use App\Enums\ParkStatus;
use App\Enums\ParkType;
use App\Enums\SiteType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\VerificationStatus;
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
    'polygon', 'lat', 'lng', 'moisture_min', 'moisture_max', 'auto_irrigation', 'baseline_l_per_day',
    'status', 'status_changed_at', 'last_reading_at', 'source', 'is_test',
    'outline_source', 'outline_reference', 'outline_by', 'outline_at',
    'area_source', 'area_reference', 'area_by', 'area_at', 'ndvi_recompute_at',
    'site_type', 'slug', 'osm_id', 'green_zone', 'green_zone_area_m2', 'geometry_source', 'geometry_updated_at',
    'monitoring_mode', 'verification_status', 'verified_by', 'verified_at', 'archived_at', 'demo_role',
    'cover_photo_path', 'cover_photo_at',
])]
class Park extends Model
{
    /** @use HasFactory<ParkFactory> */
    use HasFactory;

    /** Defaults that follow the older columns, so parks created without the Sprint 6.2 fields stay correct. */
    protected static function booted(): void
    {
        static::creating(function (Park $park) {
            $park->site_type ??= $park->is_test ? SiteType::DemoStand : SiteType::Park;
            $park->monitoring_mode ??= $park->source === DataSource::Simulated ? MonitoringMode::Simulated : MonitoringMode::RealSensors;
            $park->geometry_source ??= match ($park->getAttributes()['outline_source'] ?? null) {
                'osm' => GeometrySource::Osm,
                'traced', 'official' => GeometrySource::Traced,
                default => GeometrySource::RoughLegacy,
            };
            $park->verification_status ??= VerificationStatus::Unverified;
        });
    }

    protected function casts(): array
    {
        return [
            'park_type' => ParkType::class,
            'polygon' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'moisture_min' => 'integer',
            'moisture_max' => 'integer',
            'auto_irrigation' => 'boolean',
            'area_m2' => 'integer',
            'baseline_l_per_day' => 'integer',
            'status' => ParkStatus::class,
            'status_changed_at' => 'datetime',
            'last_reading_at' => 'datetime',
            'source' => DataSource::class,
            'is_test' => 'boolean',
            'outline_at' => 'datetime',
            'area_at' => 'datetime',
            'ndvi_recompute_at' => 'datetime',
            'site_type' => SiteType::class,
            'green_zone' => 'array',
            'green_zone_area_m2' => 'integer',
            'geometry_source' => GeometrySource::class,
            'geometry_updated_at' => 'datetime',
            'monitoring_mode' => MonitoringMode::class,
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
            'archived_at' => 'datetime',
            'cover_photo_at' => 'datetime',
        ];
    }

    /** Who drew or confirmed the outline (data sources, Sprint 3.1). @return BelongsTo<User, $this> */
    public function outlineBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outline_by');
    }

    /** @return BelongsTo<User, $this> */
    public function areaBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'area_by');
    }

    /** An NDVI recompute queued after an outline change is still running (stale after 30 min). */
    public function ndviRecomputing(): bool
    {
        return $this->ndvi_recompute_at !== null && $this->ndvi_recompute_at->gt(now()->subMinutes(30));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<ParkClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(ParkClaim::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
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

    /** Open alerts, newest first. @return HasMany<Alert, $this> */
    public function openAlerts(): HasMany
    {
        return $this->hasMany(Alert::class)->where('status', AlertStatus::Open)
            ->orderByRaw(TaskPriority::orderSql())->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** Open and in-progress tasks, oldest first. @return HasMany<Task, $this> */
    public function activeTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereIn('status', TaskStatus::active())
            ->orderByRaw(TaskPriority::orderSql())->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Node, $this> */
    public function sensorNodes(): HasMany
    {
        return $this->hasMany(Node::class)->where('type', NodeType::Sensor);
    }

    /**
     * Workers holding an open or in-progress task here: they get this park's
     * live events on their user.{id} channel.
     *
     * @return list<int>
     */
    public function activeWorkerIds(): array
    {
        return $this->tasks()->active()->whereNotNull('assigned_to')->distinct()->pluck('assigned_to')->map(fn ($id) => (int) $id)->all();
    }

    /** Middle of the moisture range: automatic irrigation stops here. */
    public function moistureMid(): float
    {
        return ($this->moisture_min + $this->moisture_max) / 2;
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

    /** The newest NDVI value (map layer). @return HasOne<NdviReading, $this> */
    public function latestNdvi(): HasOne
    {
        return $this->hasOne(NdviReading::class)->latestOfMany('date');
    }

    /** Leaves out the test bench park (unless an admin asks for it). @param Builder<Park> $query */
    public function scopeWithoutTest(Builder $query): void
    {
        $query->where('is_test', false);
    }

    /** Real parks only: not the demo stand, not archived (Sprint 6.2). @param Builder<Park> $query */
    public function scopeParks(Builder $query): void
    {
        $query->where('site_type', SiteType::Park->value)->whereNull('archived_at');
    }

    /** @param Builder<Park> $query */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }
}
