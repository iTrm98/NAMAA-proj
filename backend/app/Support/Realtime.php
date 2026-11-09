<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a broadcast event after the current database transaction commits
 * (so the dashboard never refetches data that is not saved yet), and never
 * lets a Reverb failure break a reading, a task or a job.
 */
final class Realtime
{
    public static function send(object $event): void
    {
        DB::afterCommit(function () use ($event) {
            try {
                event($event);
            } catch (Throwable $e) {
                Log::warning('Broadcast failed: '.$e->getMessage(), ['event' => $event::class]);
            }
        });
    }
}
