<?php

namespace App\Events;

/** A camera frame was checked by the vision service (every frame, litter or not). */
class DetectionCreated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'detection.created';
    }
}
