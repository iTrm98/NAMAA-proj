<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Services\OfflineChecker;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:check-offline {--for=0 : keep checking for this many seconds (the schedule uses 55)}')]
#[Description('Raise offline alerts for sensor nodes that stopped reporting (60 s, 10 s demo).')]
class CheckOffline extends Command
{
    use RepeatsWithinMinute;

    public function handle(OfflineChecker $checker): int
    {
        $this->repeat(Namaa::timing('offline_check_seconds'), function () use ($checker) {
            $raised = $checker->run();
            if ($raised > 0) {
                $this->info(now()->format('H:i:s')." offline alerts raised: $raised");
            }
        });

        return self::SUCCESS;
    }
}
