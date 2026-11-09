<?php

namespace App\Events;

/** The rules engine raised an alert. */
class AlertRaised extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'alert.raised';
    }
}
