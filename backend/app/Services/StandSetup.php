<?php

namespace App\Services;

use App\Console\Commands\BenchSetup;
use App\Enums\MonitoringMode;
use App\Enums\SiteType;
use App\Models\Node;
use App\Models\Park;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The demo stand (Sprint 6.2, Phase A): the Workshop park becomes site_type demo_stand,
 * named "NAMAA demo stand". With $move the physical boards (Model A, Model B, Camera 1,
 * or an explicit list of node ids) move onto it: only nodes.park_id changes, tokens belong
 * to the nodes and stay valid. A park left without nodes goes satellite_only.
 */
class StandSetup
{
    public const NAME_EN = 'NAMAA demo stand';

    public const NAME_AR = 'منصة نماء التجريبية';

    /** The boards that sit on the table today. */
    public const DEFAULT_NODES = ['Model A', 'Model B', 'Camera 1'];

    public function __construct(
        private readonly ParkModeService $modes,
        private readonly ParkStatusService $status,
        private readonly ActivityLogger $logger,
    ) {}

    /** The stand: by site_type first, then the Workshop park by name. */
    public static function find(?int $companyId = null): ?Park
    {
        $q = fn () => Park::when($companyId, fn ($q) => $q->where('company_id', $companyId))->whereNull('archived_at');

        return $q()->where('site_type', SiteType::DemoStand->value)->orderBy('id')->first()
            ?? $q()->where('name_en', BenchSetup::PARK_EN)->orderBy('id')->first();
    }

    /**
     * @param  list<int>|null  $nodeIds  explicit nodes to move (overrides the defaults)
     * @return array<string, mixed> the plan
     */
    public function run(bool $move = false, ?array $nodeIds = null, bool $dryRun = false): array
    {
        $stand = self::find() ?? throw new InvalidArgumentException('No demo stand park found (site_type demo_stand or the Workshop park). Run namaa:bench:setup first.');

        $moving = collect();
        if ($move) {
            $moving = $nodeIds !== null
                ? Node::with('park')->whereIn('id', $nodeIds)->get()
                : Node::with('park')->whereIn('name', self::DEFAULT_NODES)
                    ->whereHas('park', fn ($p) => $p->where('company_id', $stand->company_id))->get();

            if ($nodeIds !== null && $moving->count() !== count(array_unique($nodeIds))) {
                throw new InvalidArgumentException('Some of the given node ids do not exist.');
            }
            foreach ($moving as $node) {
                if ($node->park->company_id !== $stand->company_id) {
                    throw new InvalidArgumentException("Node #{$node->id} belongs to another company.");
                }
            }
            $moving = $moving->reject(fn (Node $n) => $n->park_id === $stand->id)->values();
        }

        $origins = $moving->pluck('park')->unique('id')->values();
        $plan = [
            'stand' => ['id' => $stand->id, 'from' => $stand->name_en, 'to' => self::NAME_EN, 'site_type' => 'demo_stand'],
            'moves' => $moving->map(fn (Node $n) => ['node_id' => $n->id, 'name' => $n->name, 'from_park' => $n->park->name_en])->all(),
            'satellite_only' => [],
            'dry_run' => $dryRun,
        ];

        // A park is left empty when every one of its nodes moves.
        $emptied = $origins->filter(fn (Park $p) => $p->nodes()->whereNotIn('id', $moving->pluck('id'))->doesntExist()
            && $p->monitoring_mode !== MonitoringMode::SatelliteOnly && $p->demo_role === null);
        $plan['satellite_only'] = $emptied->pluck('name_en')->all();

        if ($dryRun) {
            return $plan;
        }

        DB::transaction(function () use ($stand, $moving, $emptied, $origins) {
            $before = ['site_type' => $stand->site_type?->value, 'name_en' => $stand->name_en, 'name_ar' => $stand->name_ar];
            $stand->update(['site_type' => SiteType::DemoStand, 'name_en' => self::NAME_EN, 'name_ar' => self::NAME_AR, 'is_test' => true]);
            $this->logger->log('park.updated', $stand, ['before' => $before, 'after' => ['site_type' => 'demo_stand', 'name_en' => self::NAME_EN, 'name_ar' => self::NAME_AR]], companyId: $stand->company_id);

            foreach ($moving as $node) {
                $from = $node->park_id;
                $node->update(['park_id' => $stand->id, 'detached_at' => null]);
                $this->logger->log('node.updated', $node, ['before' => ['park_id' => $from], 'after' => ['park_id' => $stand->id, 'reason' => 'stand_setup']], companyId: $stand->company_id);
            }

            foreach ($emptied as $park) {
                $this->modes->apply($park->refresh(), MonitoringMode::SatelliteOnly);
            }
            foreach ($origins->diff($emptied) as $park) {
                $this->status->recalculate($park->refresh());
            }
            $this->status->recalculate($stand->refresh());
        });

        return $plan;
    }
}
