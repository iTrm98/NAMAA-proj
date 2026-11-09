<?php

namespace App\Events;

/** An admin created a task. */
class TaskCreated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'task.created';
    }
}
