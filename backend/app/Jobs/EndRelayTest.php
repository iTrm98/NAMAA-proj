<?php

namespace App\Jobs;

use App\Enums\PumpReason;
use App\Events\NodeUpdated;
use App\Models\Node;
use App\Services\PumpService;
use App\Support\Realtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Ends a relay test: switches the pump off again, unless the test was replaced or ended already. */
class EndRelayTest implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $nodeId, public readonly string $testId) {}

    public function handle(PumpService $pump): void
    {
        $node = Node::with('park')->find($this->nodeId);
        if (! $node || (Cache::get(PumpService::relayTestKey($node->id))['id'] ?? null) !== $this->testId) {
            return;
        }

        Cache::forget(PumpService::relayTestKey($node->id));
        $pump->set($node, false, PumpReason::Test);
        Realtime::send(new NodeUpdated($node->park->company_id, ['node_id' => $node->id, 'park_id' => $node->park_id, 'change' => 'relay_test']));
    }
}
