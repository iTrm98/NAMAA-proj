<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Base for every live event (api.md, Realtime). Sent at once (no queue) to:
 * company.{id} (admins), municipality (read-only viewers) and user.{id} for
 * the workers named in $userIds. Payloads stay small: the dashboard refetches
 * the details. Send through App\Support\Realtime::send().
 */
abstract class NamaaBroadcast implements ShouldBroadcastNow
{
    /** @param list<int> $userIds */
    public function __construct(
        public readonly int $companyId,
        public readonly array $payload,
        public readonly array $userIds = [],
        public readonly bool $toMunicipality = true,
    ) {}

    abstract public function broadcastAs(): string;

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('company.'.$this->companyId)];
        // Events of test-bench parks (is_test) never go to the municipality.
        if ($this->toMunicipality) {
            $channels[] = new PrivateChannel('municipality');
        }

        foreach (array_values(array_unique(array_filter($this->userIds))) as $userId) {
            $channels[] = new PrivateChannel('user.'.$userId);
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
