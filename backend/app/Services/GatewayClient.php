<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The server's only way to talk to the Gateway: pump commands, auto mode and the litter forward.
 * 2 s timeout; any failure is a GatewayUnreachable.
 */
class GatewayClient
{
    public const LITTER_OK = 'namaa:gateway:litter_ok';

    public static function configured(): bool
    {
        return (string) config('gateway.url') !== '';
    }

    /** Pump command. $seconds is clamped to 1..30; null or $on = false means "off" / the default. */
    public function command(string $zone, bool $on, ?int $seconds = null): void
    {
        $query = ['zone' => $zone, 'action' => $on ? 'on' : 'off'];
        if ($on) {
            $query['sec'] = max(1, min(30, $seconds ?? (int) config('namaa.stand.pump_max_seconds')));
        }
        $this->get('/api/cmd', $query);
    }

    public function auto(bool $on): void
    {
        $this->get('/api/auto', ['on' => $on ? 1 : 0]);
    }

    /** Tell the Gateway what the camera of a zone saw. count 0 also sends clear=1. */
    public function litter(string $zone, int $count, float $conf): void
    {
        $query = ['zone' => $zone, 'count' => $count, 'conf' => round($conf, 3), 'source' => 'NAMAA'];
        if ($count === 0) {
            $query['clear'] = 1;
        }
        $this->get('/api/litter', $query);
    }

    /** The litter forward of a camera frame: never throws, never blocks the pipeline. */
    public function forwardLitter(string $zone, int $count, float $conf): bool
    {
        if (! self::configured()) {
            return false;
        }
        try {
            $this->litter($zone, $count, $conf);
            Cache::put(self::LITTER_OK, true, now()->addHour());

            return true;
        } catch (GatewayUnreachable $e) {
            Cache::put(self::LITTER_OK, false, now()->addHour());
            Log::warning('Gateway litter forward failed: '.$e->getMessage());

            return false;
        }
    }

    /** @param array<string, scalar> $query */
    private function get(string $path, array $query): void
    {
        $base = (string) config('gateway.url');
        if ($base === '') {
            throw new GatewayUnreachable('GATEWAY_URL is not set.');
        }

        try {
            Http::timeout((int) config('gateway.timeout'))->get($base.$path, $query)->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new GatewayUnreachable('Gateway did not answer: '.$e->getMessage(), previous: $e);
        }
    }
}
