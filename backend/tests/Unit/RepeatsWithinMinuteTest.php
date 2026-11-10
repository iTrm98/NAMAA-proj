<?php

namespace Tests\Unit;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use PHPUnit\Framework\TestCase;

/** The scheduled --for loops stop at second 50 so the next minute's run is never skipped. */
class RepeatsWithinMinuteTest extends TestCase
{
    private function budget(int $for, int $second): float
    {
        $command = new class
        {
            use RepeatsWithinMinute;
        };

        return $command::repeatBudget($for, 1_800_000_000 - fmod(1_800_000_000, 60) + $second);
    }

    public function test_no_for_option_runs_once(): void
    {
        $this->assertSame(0.0, $this->budget(0, 5));
    }

    public function test_a_slow_boot_shortens_the_loop_to_second_50(): void
    {
        $this->assertEqualsWithDelta(41.0, $this->budget(55, 9), 0.001);
    }

    public function test_an_early_start_keeps_the_for_limit(): void
    {
        $this->assertEqualsWithDelta(30.0, $this->budget(30, 2), 0.001);
    }

    public function test_starting_after_second_50_runs_once(): void
    {
        $this->assertSame(0.0, $this->budget(55, 55));
    }
}
