<?php

namespace App\Events;

/** A park status changed (ok, dry, over, offline). */
class ParkStatusChanged extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'park.status_changed';
    }
}
