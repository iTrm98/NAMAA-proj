<?php

namespace App\Services;

use App\Enums\PumpReason;
use App\Enums\SiteType;
use App\Events\PumpChanged;
use App\Jobs\EndRelayTest;
use App\Models\Node;
use App\Models\Park;
use App\Models\PumpLog;
use App\Models\User;
use App\Support\Namaa;
use App\Support\Realtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The server decides the pump: this is the only place that changes
 * nodes.pump_target. Every change writes pump_logs + activity_logs
 * (pump.switched) and broadcasts pump.changed. The board applies the target
 * from its next reading reply.
 */
class PumpService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /** Returns false when the target already had that value (nothing logged). */
    public function set(Node $node, bool $on, PumpReason $reason, ?User $user = null): bool
    {
        if (! $node->has_pump) {
            throw new InvalidArgumentException("Node {$node->id} has no pump.");
        }

        if ($node->pump_target === $on) {
            return false;
        }

        $node->update(['pump_target' => $on]);

        $log = PumpLog::create([
            'node_id' => $node->id,
            'user_id' => $user?->id,
            'target' => $on,
            'reason' => $reason,
        ]);

        $park = $node->park;

        $this->logger->log('pump.switched', $node, [
            'before' => ['pump_target' => ! $on],
            'after' => ['pump_target' => $on, 'reason' => $reason->value],
        ], user: $user, companyId: $park->company_id);

        Realtime::send(new PumpChanged($park->company_id, [
            'park_id' => $park->id,
            'node_id' => $node->id,
            'pump_target' => $on,
            'reason' => $reason->value,
            'by' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
            'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $park->activeWorkerIds()));

        return true;
    }

    public static function relayTestKey(int $nodeId): string
    {
        return "namaa:relay_test:$nodeId";
    }

    /** End time of a running relay test (hardware page), else null. */
    public static function relayTestUntil(int $nodeId): ?Carbon
    {
        $test = Cache::get(self::relayTestKey($nodeId));

        return $test && Carbon::parse($test['until'])->isFuture() ? Carbon::parse($test['until']) : null;
    }

    /**
     * Relay test: the pump runs relay_test_seconds through the normal pump path (the board
     * gets pump:true in its next reply), then EndRelayTest switches it off. Returns the end time.
     */
    public function startRelayTest(Node $node, User $user): Carbon
    {
        $until = now()->addSeconds(Namaa::timing('relay_test_seconds'));
        $id = (string) Str::uuid();

        Cache::put(self::relayTestKey($node->id), ['id' => $id, 'until' => $until->toIso8601String()], $until->copy()->addMinute());
        $this->set($node, true, PumpReason::Test, $user);
        EndRelayTest::dispatch($node->id, $id)->delay($until);

        return $until;
    }

    /** The newest pump log of a node (who switched it, why, when). */
    public function lastChange(Node $node): ?PumpLog
    {
        return $node->pumpLogs()->with('user')->latest('created_at')->latest('id')->first();
    }

    /**
     * The pump card of a park (api.md, park detail `pump`): target, what the
     * board reports, who switched it last and why, and the safety cut-off time.
     *
     * @return array<string, mixed>|null null when the park has no pump
     */
    public function state(Park $park): ?array
    {
        $node = $park->relationLoaded('nodes')
            ? $park->nodes->sortBy('id')->first(fn (Node $n) => $n->type->value === 'sensor' && $n->has_pump)
            : $park->pumpNode()->first();

        if (! $node) {
            return null;
        }

        $last = $this->lastChange($node);
        $iso = fn ($t) => $t?->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'node_id' => $node->id,
            'target' => $node->pump_target,
            'reported_on' => $node->last_pump_on,
            'changed_at' => $iso($last?->created_at),
            'reason' => $last?->reason->value,
            'by' => $last?->user ? ['id' => $last->user->id, 'name' => $last->user->name] : null,
            'cutoff_at' => $iso($this->cutoffAt($node, $last)),
            'max_run_seconds' => $this->maxRun($node),
        ];
    }

    /** Max run time of a node's pump: the stand has its own, shorter limit. */
    public function maxRun(Node $node): int
    {
        return $node->isStand() ? (int) config('namaa.stand.pump_max_seconds') : Namaa::timing('pump_max_run_seconds');
    }

    /** When a pump that is on will be switched off by the safety cut-off (null when off). */
    public function cutoffAt(Node $node, ?PumpLog $last = null): ?Carbon
    {
        if (! $node->pump_target) {
            return null;
        }

        $last ??= $this->lastChange($node);
        $since = $last && $last->target ? $last->created_at : now();

        return $since->copy()->addSeconds($this->maxRun($node));
    }

    /**
     * Safety cut-off: switch off every pump that has been on longer than the
     * maximum run time. Returns the number of pumps switched off.
     */
    public function cutOffLongRuns(): int
    {
        $count = 0;
        $maxRun = Namaa::timing('pump_max_run_seconds');

        Node::query()->where('has_pump', true)->where('pump_target', true)
            ->whereDoesntHave('park', fn ($p) => $p->where('site_type', SiteType::DemoStand->value))
            ->with('park')->each(function (Node $node) use (&$count, $maxRun) {
            $last = $this->lastChange($node);

            // No "on" log: the start time is unknown, so switch it off now.
            if (! $last || ! $last->target || $last->created_at->lte(now()->subSeconds($maxRun))) {
                $count += (int) $this->set($node, false, PumpReason::Safety);
            }
        });

        return $count;
    }

    /**
     * Demo stand watchdog: a pump that is on goes off after the stand's max run time
     * (auto_off_max) or when the board has not checked in for stand_checkin_seconds
     * (auto_off_no_checkin). Returns the number of pumps switched off.
     */
    public function standWatchdog(): int
    {
        $count = 0;

        Node::query()->where('has_pump', true)->where('pump_target', true)
            ->whereHas('park', fn ($p) => $p->where('site_type', SiteType::DemoStand->value))
            ->with('park')->each(function (Node $node) use (&$count) {
                $last = $this->lastChange($node);
                $since = $last && $last->target ? $last->created_at : null;

                if ($since === null || $since->lte(now()->subSeconds($this->maxRun($node)))) {
                    $count += (int) $this->set($node, false, PumpReason::AutoOffMax);
                } elseif ($this->silent($node)) {
                    $count += (int) $this->set($node, false, PumpReason::AutoOffNoCheckin);
                }
            });

        return $count;
    }

    /** A stand board that has not reported for stand_checkin_seconds (or never). */
    public function silent(Node $node): bool
    {
        return $node->last_seen_at === null || $node->last_seen_at->lte(now()->subSeconds(Namaa::timing('stand_checkin_seconds')));
    }
}
