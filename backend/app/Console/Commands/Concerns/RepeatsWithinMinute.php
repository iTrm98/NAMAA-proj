<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Booting Laravel takes about 4 s on the Windows bind mount, too slow to start
 * a new process every 5 s in demo mode. The schedule therefore starts these
 * commands once a minute with --for=55 and they repeat their work inside that
 * minute at the configured interval. Without --for they run once (tests,
 * manual runs).
 *
 * The loop also stops at second STOP_AT_SECOND of the minute it started in.
 * withoutOverlapping only releases its lock when `schedule:finish` (a second
 * PHP boot) has run, so a loop that ran to second 59 or later made the
 * scheduler skip the whole next minute: a 60+ s gap in readings, longer than
 * the demo offline window. Stopping at :50 leaves room for that boot.
 */
trait RepeatsWithinMinute
{
    public const STOP_AT_SECOND = 50;

    /** @param callable(): void $work */
    protected function repeat(int $everySeconds, callable $work): void
    {
        $started = microtime(true);
        $for = self::repeatBudget((int) ($this->option('for') ?? 0), $started);
        $everySeconds = max(1, $everySeconds);

        do {
            $runStarted = microtime(true);
            $work();

            $next = $runStarted + $everySeconds;
            if ($next - $started + 0.5 > $for) {
                break;
            }

            DB::disconnect(); // do not hold a connection while sleeping
            usleep((int) max(0, ($next - microtime(true)) * 1_000_000));
        } while (true);
    }

    /** Seconds the loop may run: --for, but never past STOP_AT_SECOND of the starting minute. */
    public static function repeatBudget(int $for, float $startedAt): float
    {
        if ($for <= 0) {
            return 0.0;
        }
        $secondOfMinute = fmod($startedAt, 60.0);

        return max(0.0, min((float) $for, self::STOP_AT_SECOND - $secondOfMinute));
    }
}
