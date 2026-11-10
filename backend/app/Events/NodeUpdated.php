<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;

/** A node's calibration, camera mode or relay test changed (hardware page). Company channel only. */
class NodeUpdated extends NamaaBroadcast
{
    public function broadcastAs(): string
    {
        return 'node.updated';
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('company.'.$this->companyId)];
    }
}
