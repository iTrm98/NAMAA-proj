<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The stand camera's mode, one value for the whole server (the demo has one stand):
 *  - demo (default): the real flow, frames become detections, alerts and tasks;
 *  - test: the litter AI runs on frames for the Live device page only. The polling job
 *    and the scenario feed skip while it is on, and nothing is ever stored.
 * Kept in the cache so the scheduler and queue containers see it too. A scenario Reset puts it back to demo.
 */
class CameraMode
{
    public const KEY = 'namaa:camera:mode';

    public const DEMO = 'demo';

    public const TEST = 'test';

    public static function current(): string
    {
        return Cache::get(self::KEY) === self::TEST ? self::TEST : self::DEMO;
    }

    public static function isTest(): bool
    {
        return self::current() === self::TEST;
    }

    public static function set(string $mode): string
    {
        $mode = $mode === self::TEST ? self::TEST : self::DEMO;
        Cache::forever(self::KEY, $mode);
        // Switching either way starts a new run: the 2-of-3 memory of the test frames is dropped.
        CameraTester::forgetPrevious();

        return $mode;
    }
}
