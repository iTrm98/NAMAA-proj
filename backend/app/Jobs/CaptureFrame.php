<?php

namespace App\Jobs;

use App\Services\CameraPipeline;
use App\Services\VisionUnavailable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

/**
 * One frame from CAMERA_URL/capture through the camera pipeline. Run by the
 * scheduler (namaa:capture, every 5 min / 10 s demo) with dispatchSync: it
 * must not wait behind the slow NDVI jobs on the queue. A camera or vision
 * failure is a warning, never a crash; the camera node then goes offline
 * through the normal offline rule.
 */
class CaptureFrame
{
    use Dispatchable;

    public function handle(CameraPipeline $pipeline): void
    {
        try {
            $pipeline->capture();
        } catch (VisionUnavailable $e) {
            Log::warning('Camera frame skipped: '.$e->getMessage());
        }
    }
}
