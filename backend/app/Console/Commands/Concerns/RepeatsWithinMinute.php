<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Booting Laravel takes about 4 s on the Windows bind mount, too slow to start
 * a new process every 5 s in demo mode. The schedule therefore starts these
 * commands once a minute with --for=55 and they repeat their work inside that
 * minute at the configured interval. Without --for they run once (tests,
 * manual runs).
 */
trait RepeatsWithinMinute
{
    /** @param callable(): void $work */
    protected function repeat(int $everySeconds, callable $work): void
    {
        $for = (int) ($this->option('for') ?? 0);
        $started = microtime(true);
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
}
