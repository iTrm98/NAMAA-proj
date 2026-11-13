<?php

use App\Jobs\QueueHeartbeat;
use App\Services\SystemHealth;
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
|   namaa:stand-safety    demo stand pump watchdog         every 2 s
|   namaa:verify-tasks    sustained-reading verification   every 60 s (10 s demo)
|   namaa:escalate-tasks  overdue open tasks               every 60 s (10 s demo)
|   namaa:capture         camera frame + litter rules      every 5 min (10 s demo), only with CAMERA_URL
|
| Daily (Asia/Riyadh): namaa:ndvi 03:10 (queued, one job per park), namaa:prune-camera 03:30.
|
| namaa:check-offline also makes offline alerts task-ready and raises them.
*/

foreach (['namaa:simulate', 'namaa:check-offline', 'namaa:pump-safety', 'namaa:stand-safety', 'namaa:verify-tasks', 'namaa:escalate-tasks', 'namaa:capture'] as $command) {
    Schedule::command("$command --for=55")
        ->everyMinute()
        ->withoutOverlapping(2)
        ->runInBackground();
}

// Heartbeats for namaa:doctor: the scheduler writes its own, and pushes one job through the queue.
Schedule::call(fn () => SystemHealth::beat())->name('heartbeat:scheduler')->everyMinute();
Schedule::job(new QueueHeartbeat)->name('heartbeat:queue')->everyMinute();

Schedule::command('namaa:ndvi --days=35')->dailyAt('03:10')->timezone('Asia/Riyadh')->withoutOverlapping();
Schedule::command('namaa:prune-camera')->dailyAt('03:30')->timezone('Asia/Riyadh')->withoutOverlapping();
