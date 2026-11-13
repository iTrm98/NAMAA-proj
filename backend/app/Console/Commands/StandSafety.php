<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Services\PumpService;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:stand-safety {--for=0 : keep checking for this many seconds (the schedule uses 55)}')]
#[Description('Demo stand watchdog: pump off after the max run time or when the board has not checked in for 10 s.')]
class StandSafety extends Command
{
    use RepeatsWithinMinute;

    public function handle(PumpService $pump): int
    {
        $this->repeat(Namaa::timing('stand_check_seconds'), function () use ($pump) {
            $off = $pump->standWatchdog();
            if ($off > 0) {
                $this->info(now()->format('H:i:s')." stand pumps switched off: $off");
            }
        });

        return self::SUCCESS;
    }
}
