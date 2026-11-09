<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Small access point for NAMAA settings (config/namaa.php).
 *
 *   Namaa::timing('offline_seconds')   // 300, or 30 when DEMO_MODE=true
 *
 * Code never checks demo_mode by hand for a timing.
 */
final class Namaa
{
    public static function demoMode(): bool
    {
        return (bool) config('namaa.demo_mode');
    }

    /** A timing in seconds, resolved for the current mode. */
    public static function timing(string $key): int
    {
        $values = config("namaa.timings.$key");

        if (! is_array($values)) {
            throw new InvalidArgumentException("Unknown NAMAA timing [$key].");
        }

        return (int) $values[self::demoMode() ? 'demo' : 'normal'];
    }

    /** A rules-engine setting (config namaa.rules). */
    public static function rule(string $key): int|float
    {
        return config("namaa.rules.$key");
    }
}
