<?php

namespace App\Events;

/** A task changed: assigned, started, done, verified, cancelled, ... */
class TaskUpdated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'task.updated';
    }
}
