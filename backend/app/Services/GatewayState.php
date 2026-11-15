<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * What the Gateway last told us, kept in the cache (no table): the newest telemetry summary,
 * the per-zone water baseline for "today" and a small event timeline.
 */
class GatewayState
{
    private const STATE = 'namaa:gateway:state';

    private const TIMELINE = 'namaa:gateway:timeline';

    /** @return array<string, mixed> */
    public function get(): array
    {
        return Cache::get(self::STATE, []);
    }

    /** @param array<string, mixed> $patch */
    public function merge(array $patch): void
    {
        Cache::put(self::STATE, $patch + $this->get(), now()->addDay());
    }

    public function lastEventAt(): ?\Illuminate\Support\Carbon
    {
        $at = $this->get()['last_event_at'] ?? null;

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    public function reachable(): bool
    {
        $at = $this->lastEventAt();

        return $at !== null && $at->gt(now()->subSeconds((int) config('gateway.offline_seconds')));
    }

    /** Makkah local day (Asia/Riyadh) used for the "water today" reset. */
    private function day(): string
    {
        return now('Asia/Riyadh')->format('Y-m-d');
    }

    /**
     * Litres since Makkah local midnight from the totalMl counter; a counter that goes down is a reset.
     * On day rollover the delta since the last sample counts toward the new day.
     */
    public function addTotal(string $zone, int $totalMl): void
    {
        $key = 'namaa:gateway:water:'.$zone;
        $s = Cache::get($key, ['day' => $this->day(), 'acc' => 0, 'last' => null]);
        $delta = $s['last'] === null ? 0 : ($totalMl >= $s['last'] ? $totalMl - $s['last'] : $totalMl);
        $acc = ($s['day'] ?? null) === $this->day() ? $s['acc'] : 0;
        Cache::put($key, ['day' => $this->day(), 'acc' => $acc + $delta, 'last' => $totalMl], now()->addDays(2));
    }

    public function waterTodayMl(string $zone): int
    {
        $s = Cache::get('namaa:gateway:water:'.$zone);

        return $s && ($s['day'] ?? null) === $this->day() ? (int) $s['acc'] : 0;
    }

    /** @param array<string, mixed> $params */
    public function push(string $kind, ?string $zone, array $params = []): void
    {
        $events = Cache::get(self::TIMELINE, []);
        array_unshift($events, [
            'id' => 'g'.now()->getTimestampMs().'-'.count($events),
            'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'kind' => $kind, 'zone' => $zone, 'params' => $params,
        ]);
        Cache::put(self::TIMELINE, array_slice($events, 0, 40), now()->addDay());
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return Cache::get(self::TIMELINE, []);
    }

    /** Litter seen flag of a zone camera: returns the previous value. */
    public function swapLitter(string $zone, bool $seen): bool
    {
        $key = 'namaa:gateway:litter:'.$zone;
        $prev = (bool) Cache::get($key, false);
        Cache::put($key, $seen, now()->addDay());

        return $prev;
    }
}
