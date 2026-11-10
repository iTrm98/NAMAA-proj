<?php

namespace App\Jobs;

use App\Services\SystemHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Pushed by the scheduler every minute: proves the queue worker runs (namaa:doctor). */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put(SystemHealth::QUEUE_BEAT, now()->toIso8601String(), now()->addDay());
    }
}
