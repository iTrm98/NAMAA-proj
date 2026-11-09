<?php

namespace App\Events;

/** A sensor reading was stored: park id, latest values, park status. */
class ReadingReceived extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'reading.received';
    }
}
