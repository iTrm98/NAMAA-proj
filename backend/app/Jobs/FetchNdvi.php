<?php

namespace App\Jobs;

use App\Models\Park;
use App\Services\NdviService;
use App\Services\VisionUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NDVI for one park (queued: a Sentinel-2 search can take a minute). A vision
 * or STAC failure is retried twice (after 1 and 5 minutes) before it fails.
 */
class FetchNdvi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than the vision call's own 120 s limit. */
    public int $timeout = 150;

    public function __construct(
        public readonly int $parkId,
        public readonly int $days = 10,
        public readonly ?string $runId = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NdviService $ndvi): void
    {
        $park = Park::find($this->parkId);
        if (! $park || empty($park->polygon)) {
            NdviService::progress($this->runId, $this->parkId, 'skipped');

            return;
        }

        try {
            $result = $ndvi->fetch($park, $this->days);
        } catch (VisionUnavailable $e) {
            Log::warning('NDVI fetch failed', ['park_id' => $this->parkId, 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);
            throw $e; // the queue retries (tries / backoff)
        }

        NdviService::progress($this->runId, $this->parkId, 'done', $result['values']);
    }

    public function failed(?Throwable $e): void
    {
        NdviService::progress($this->runId, $this->parkId, 'failed');
    }
}
