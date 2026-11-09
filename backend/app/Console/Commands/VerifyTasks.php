<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Services\TaskVerifier;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:verify-tasks {--for=0 : keep checking for this many seconds (the schedule uses 55)}')]
#[Description('Verify done tasks by sustained normal readings (every 60 s, 10 s demo).')]
class VerifyTasks extends Command
{
    use RepeatsWithinMinute;

    public function handle(TaskVerifier $verifier): int
    {
        $this->repeat(Namaa::timing('verify_check_seconds'), function () use ($verifier) {
            $decided = $verifier->run();
            if ($decided > 0) {
                $this->info(now()->format('H:i:s')." tasks decided: $decided");
            }
        });

        return self::SUCCESS;
    }
}
