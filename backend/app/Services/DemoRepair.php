<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Models\Task;
use App\Support\Namaa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A worker's repair ends the simulated fault (simulated nodes only, never real
 * ones): when a task is marked Done, the forced fault the task was about is
 * switched off in the simulator, so the verification passes on real readings.
 *
 *   leak, blockage, no_flow  -> the forced leak / blockage flag is cleared;
 *   litter, litter_repeated  -> on a simulated camera in demo mode, the camera
 *                               sends the clean sample photos next (CameraFeed).
 */
class DemoRepair
{
    public function __construct(private readonly CameraFeed $feed) {}

    /** @return string|null what was ended (the flag or "clean_frames"), null when nothing */
    public function afterDone(Task $task): ?string
    {
        $alert = $task->alert()->with('node')->first();
        $node = $alert?->node;
        if (! $alert || ! $node || $node->source !== DataSource::Simulated) {
            return null;
        }

        switch ($alert->type) {
            case AlertType::Leak:
                Cache::forget(ScenarioService::cacheKey($node->id, 'leak'));

                return 'leak';
            case AlertType::Blockage:
            case AlertType::NoFlow:
                Cache::forget(ScenarioService::cacheKey($node->id, 'blockage'));

                return 'blockage';
            case AlertType::Litter:
            case AlertType::LitterRepeated:
                if (! Namaa::demoMode() || ! $this->feed->repaired($node)) {
                    return null;
                }
                // The first clean frame goes out right away; the rest follow at the capture interval.
                DB::afterCommit(fn () => $this->sendFirstFrame());

                return 'clean_frames';
            default:
                return null;
        }
    }

    private function sendFirstFrame(): void
    {
        try {
            if ($frame = $this->feed->next(0)) {
                app(CameraPipeline::class)->process(  // resolved late: the pipeline's rules reach TaskService, which uses this class
                    $frame['node'], $frame['bytes'], 'scenario', $frame['sample']);
            }
        } catch (Throwable $e) {
            Log::warning('First clean frame skipped: '.$e->getMessage()); // the capture loop sends the next ones
        }
    }
}
