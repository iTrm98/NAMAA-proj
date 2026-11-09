<?php

namespace App\Events;

/** An open task passed its due time: priority raised one level, or flagged overdue when already urgent. */
class TaskEscalated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'task.escalated';
    }
}
