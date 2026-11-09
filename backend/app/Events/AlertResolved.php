<?php

namespace App\Events;

/** An alert was resolved. */
class AlertResolved extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'alert.resolved';
    }
}
