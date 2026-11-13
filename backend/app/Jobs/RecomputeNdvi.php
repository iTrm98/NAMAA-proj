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
 * After an outline change (Sprint 3.1, D3): NDVI of the last 90 days again with
 * the new outline. The park's rows in that window are replaced only once the
 * vision service has answered, then the ndvi_drop rule re-evaluates the open
 * alert. parks.ndvi_recompute_at is cleared when done (or failed for good), so
 * the park page stops showing "Recomputing satellite data".
 */
class RecomputeNdvi implements ShouldQueue
{
    use Queueable;

    public const DAYS = 90;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public readonly int $parkId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NdviService $ndvi): void
    {
        $park = Park::find($this->parkId);
        if (! $park || empty($park->polygon) || ! $park->isSatelliteEligible()) {
            Park::whereKey($this->parkId)->update(['ndvi_recompute_at' => null]);

            return;
        }

        try {
            $ndvi->fetch($park, self::DAYS, replace: true);
        } catch (VisionUnavailable $e) {
            Log::warning('NDVI recompute failed', ['park_id' => $this->parkId, 'attempt' => $this->attempts(), 'error' => $e->getMessage()]);
            throw $e;
        }

        $park->forceFill(['ndvi_recompute_at' => null])->save();
    }

    public function failed(?Throwable $e): void
    {
        Park::whereKey($this->parkId)->update(['ndvi_recompute_at' => null]);
    }
}
