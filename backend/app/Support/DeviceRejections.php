<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The last rejected device requests (401, 403, 422, 429), kept in the cache:
 * per node when the token is known, else per token prefix (4 characters).
 * Only time, status and the first message are stored; never the token or the body.
 */
final class DeviceRejections
{
    public const KEEP = 20;

    private const LAST = 'namaa:device:rejected:last';

    public static function nodeKey(int $nodeId): string
    {
        return "namaa:device:rejected:node:$nodeId";
    }

    public static function record(?int $nodeId, ?string $bearer, int $status, string $message): void
    {
        $entry = ['at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => $status, 'message' => mb_substr($message, 0, 200)];

        $key = $nodeId !== null
            ? self::nodeKey($nodeId)
            : 'namaa:device:rejected:token:'.substr(hash('sha256', (string) $bearer), 0, 8);
        $list = Cache::get($key, []);
        array_unshift($list, $entry);
        Cache::put($key, array_slice($list, 0, self::KEEP), now()->addDays(7));

        Cache::put(self::LAST, $entry + [
            'node_id' => $nodeId,
            'token_prefix' => $nodeId === null && $bearer ? substr($bearer, 0, 4) : null,
        ], now()->addDays(7));
    }

    /** @return list<array{at: string, status: int, message: string}> newest first */
    public static function forNode(int $nodeId): array
    {
        return Cache::get(self::nodeKey($nodeId), []);
    }

    /** @return array<string, mixed>|null */
    public static function last(): ?array
    {
        return Cache::get(self::LAST);
    }
}
