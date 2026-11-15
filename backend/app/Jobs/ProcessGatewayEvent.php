<?php

namespace App\Jobs;

use App\Services\GatewayIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** One Gateway event (telemetry or alert), applied off the request on the `gateway` queue. */
class ProcessGatewayEvent implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @param array<string, mixed> $data */
    public function __construct(public string $event, public array $data)
    {
        $this->onQueue((string) config('gateway.queue', 'gateway'));
    }

    public function handle(GatewayIngestor $ingestor): void
    {
        $ingestor->handle($this->event, $this->data);
    }
}
