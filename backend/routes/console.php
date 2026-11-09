<?php

use Illuminate\Support\Facades\Schedule;

/*
| NAMAA schedule (the `scheduler` container runs `schedule:work`).
|
| Booting Laravel takes about 4 s on the Windows bind mount, so the
| fast jobs are started once a minute with --for=55 and repeat their work
| inside the minute at the interval from config/namaa.php (DEMO_MODE=true
| switches to the short demo values on the next minute). runInBackground lets
| them run side by side; withoutOverlapping keeps one copy of each.
|
|   namaa:simulate        one reading per simulated node   every 30 s (5 s demo)
|   namaa:check-offline   offline rule                     every 60 s (10 s demo)
|   namaa:pump-safety     pump safety cut-off              every 30 s (10 s demo)
|   namaa:verify-tasks    sustained-reading verification   every 60 s (10 s demo)
|   namaa:escalate-tasks  overdue open tasks               every 60 s (10 s demo)
|
| namaa:check-offline also makes offline alerts task-ready and raises them.
*/

foreach (['namaa:simulate', 'namaa:check-offline', 'namaa:pump-safety', 'namaa:verify-tasks', 'namaa:escalate-tasks'] as $command) {
    Schedule::command("$command --for=55")
        ->everyMinute()
        ->withoutOverlapping(2)
        ->runInBackground();
}
