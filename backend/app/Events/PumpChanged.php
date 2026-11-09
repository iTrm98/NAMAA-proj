<?php

namespace App\Events;

/** A pump target changed (manual, rule or safety). */
class PumpChanged extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'pump.changed';
    }
}
