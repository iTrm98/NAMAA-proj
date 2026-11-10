<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RepeatsWithinMinute;
use App\Jobs\CaptureFrame;
use App\Services\CameraFeed;
use App\Services\CameraPipeline;
use App\Services\LitterRules;
use App\Services\VisionUnavailable;
use App\Support\Namaa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

#[Signature('namaa:capture {--for=0 : keep capturing for this many seconds (the schedule uses 55)}')]
#[Description('Capture a camera frame and check it for litter (every 5 min, 10 s demo; only when CAMERA_URL is set).')]
class CaptureFrames extends Command
{
    use RepeatsWithinMinute;

    /** Cache key of the last capture: the 5-minute interval survives the once-a-minute restarts. */
    public const LAST = 'namaa:camera:last_capture';

    public function handle(LitterRules $litter, CameraFeed $feed, CameraPipeline $pipeline): int
    {
        $every = Namaa::timing('camera_capture_seconds');
        // Check the cadence often enough for both 10 s (demo) and 5 min (normal).
        $tick = min($every, 10);

        $this->repeat($tick, function () use ($every, $litter, $feed, $pipeline) {
            $litter->checkAge();

            // Demo scenario: sample photos replace the camera while the feed runs.
            if ($feed->current()) {
                if ($frame = $feed->next($every)) {
                    try {
                        $pipeline->process($frame['node'], $frame['bytes'], 'scenario');
                    } catch (VisionUnavailable $e) {
                        Log::warning('Scenario frame skipped: '.$e->getMessage());
                    }
                }

                return;
            }

            if (! CameraPipeline::enabled()) {
                return;
            }

            $last = Cache::get(self::LAST);
            if ($last !== null && now()->getTimestampMs() / 1000 - (float) $last < $every - 1) {
                return;
            }

            Cache::put(self::LAST, now()->getTimestampMs() / 1000, now()->addHour());
            CaptureFrame::dispatchSync();
        });

        return self::SUCCESS;
    }
}
