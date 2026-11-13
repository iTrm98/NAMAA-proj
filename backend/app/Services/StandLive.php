<?php

namespace App\Services;

use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Support\Namaa;

/**
 * The "Live device" payload of the demo stand (api.md, /api/admin/stand/live): board
 * online state, raw and calibrated moisture, trend, the pump as the board reports it,
 * calibration and the latest camera result. All times are UTC ISO 8601.
 */
class StandLive
{
    public const TREND_SECONDS = 300;

    public function __construct(
        private readonly Calibration $calibration,
        private readonly PumpService $pump,
    ) {}

    /** The stand of a company, or null. */
    public function stand(int $companyId): ?Park
    {
        return StandSetup::find($companyId);
    }

    /** The stand's soil and pump board: Bench A when present, else the first real sensor with a pump. */
    public function node(Park $stand): ?Node
    {
        return Node::where('park_id', $stand->id)->where('type', 'sensor')->where('has_pump', true)
            ->where(fn ($q) => $q->where('placeholder', false)->orWhere('name', 'Bench A'))
            ->orderByRaw("name = 'Bench A' desc")->orderBy('id')->first();
    }

    public function online(Node $node): bool
    {
        return $node->last_seen_at !== null && $node->last_seen_at->gt(now()->subSeconds(Namaa::timing('stand_checkin_seconds')));
    }

    /** @return array<string, mixed> */
    public function payload(Park $stand, Node $node): array
    {
        $node->setRelation('park', $stand);
        $iso = fn ($t) => $t?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $latest = $node->readings()->latest('created_at')->latest('id')->first();
        $m = $this->calibration->describe($node, $latest?->moisture_raw);
        $last = $this->pump->lastChange($node);
        $cutoff = $this->pump->cutoffAt($node, $last);

        $trend = $node->readings()->where('created_at', '>=', now()->subSeconds(self::TREND_SECONDS))
            ->orderBy('created_at')->orderBy('id')->limit(400)->get(['moisture_raw', 'created_at'])
            ->map(fn ($r) => [
                'at' => $iso($r->created_at),
                'raw' => $r->moisture_raw,
                'pct' => $this->calibration->describe($node, $r->moisture_raw)['pct'],
            ])->all();

        $detection = Detection::where('park_id', $stand->id)->latest('captured_at')->first();

        return [
            'node_id' => $node->id,
            'name' => $node->name,
            'park_id' => $stand->id,
            'source' => $node->source->value,
            'board' => [
                'online' => $this->online($node),
                'last_seen_at' => $iso($node->last_seen_at),
                'link' => $node->via,
                'rssi' => $node->rssi,
                'uptime_s' => $node->uptime_s,
                'fw_version' => $node->fw_version,
            ],
            'moisture' => ['pct' => $m['pct'], 'raw' => $m['raw'], 'calibrated' => $m['calibrated'], 'at' => $iso($latest?->created_at)],
            'trend' => $trend,
            'pump' => [
                'target' => (bool) $node->pump_target,
                'reported_on' => $node->last_pump_on,
                'cutoff_at' => $iso($cutoff),
                'remaining_s' => $cutoff ? max(0, (int) now()->diffInSeconds($cutoff, false)) : null,
                'max_s' => (int) config('namaa.stand.pump_max_seconds'),
                'changed_at' => $iso($last?->created_at),
                'reason' => $last?->reason->value,
                'by' => $last?->user ? ['id' => $last->user->id, 'name' => $last->user->name] : null,
            ],
            'calibration' => [
                'raw_dry' => $node->dry_value,
                'raw_wet' => $node->wet_value,
                'dry_at' => $iso($node->calibrated_dry_at),
                'wet_at' => $iso($node->calibrated_wet_at),
                'calibrated' => $m['calibrated'],
            ],
            'camera' => $detection ? [
                'detection_id' => $detection->id,
                'at' => $iso($detection->captured_at),
                'count' => $detection->count,
                'litter' => $detection->count > 0,
                'people_count' => $detection->people_count,
            ] : null,
        ];
    }
}
