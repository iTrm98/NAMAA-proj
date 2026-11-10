<?php

namespace App\Events;

/** New NDVI values were saved for a park (daily job, command or demo button). */
class NdviUpdated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'ndvi.updated';
    }
}
