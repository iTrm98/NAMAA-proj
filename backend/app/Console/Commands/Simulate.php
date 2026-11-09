<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Services\Simulator;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:simulate {--for=0 : keep ticking for this many seconds (the schedule uses 55)}')]
#[Description('Simulated parks: one reading per simulated sensor node per tick (30 s, 5 s demo).')]
class Simulate extends Command
{
    use RepeatsWithinMinute;

    public function handle(Simulator $simulator): int
    {
        $this->repeat(Namaa::timing('simulator_tick_seconds'), function () use ($simulator) {
            $this->line(now()->format('H:i:s').' simulated readings: '.$simulator->tick());
        });

        return self::SUCCESS;
    }
}
