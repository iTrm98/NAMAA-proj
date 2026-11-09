<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Services\PumpService;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:pump-safety {--for=0 : keep checking for this many seconds (the schedule uses 55)}')]
#[Description('Switch off pumps that ran longer than the maximum run time (30 s, 10 s demo).')]
class PumpSafety extends Command
{
    use RepeatsWithinMinute;

    public function handle(PumpService $pump): int
    {
        $this->repeat(Namaa::timing('pump_check_seconds'), function () use ($pump) {
            $off = $pump->cutOffLongRuns();
            if ($off > 0) {
                $this->info(now()->format('H:i:s')." pumps switched off by the safety cut-off: $off");
            }
        });

        return self::SUCCESS;
    }
}
