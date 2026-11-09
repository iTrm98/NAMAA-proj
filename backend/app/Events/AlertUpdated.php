<?php

namespace App\Events;

/** An open alert changed: priority raised, converted (dry -> heat_stress) or became task-ready. */
class AlertUpdated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'alert.updated';
    }
}
