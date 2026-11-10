<?php

namespace App\Console\Commands;

use App\Services\DataCleanup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deletes the test frames sent to Camera 1 (King Abdullah Park) before the real
 * ESP32 camera was connected: detections that did not come from the capture job,
 * their photo files, the camera's litter / litter_repeated alerts and their tasks.
 * Frames captured from CAMERA_URL (origin "camera") are kept.
 */
#[Signature('namaa:cleanup:test-camera {--dry-run : Print what would be deleted, delete nothing} {--node= : Camera node id (default: "Camera 1")}')]
#[Description('Delete the test frames, photos, litter alerts and tasks of Camera 1.')]
class CleanupTestCamera extends Command
{
    public function handle(DataCleanup $cleanup): int
    {
        $camera = DataCleanup::camera($this->option('node') ? (int) $this->option('node') : null);
        if (! $camera) {
            $this->error('Camera node not found.');

            return self::FAILURE;
        }

        $plan = $cleanup->testCameraPlan($camera);
        $dry = (bool) $this->option('dry-run');
        $this->line(sprintf('%s camera #%d "%s" on %s (%s).', $dry ? 'Dry run:' : 'Cleaning', $camera->id, $camera->name,
            $camera->park->name_en, $camera->source->value));
        $this->line(sprintf('Frames from the capture job (origin camera), kept: %d.', $plan['kept']));

        $this->table(['Detection', 'Origin', 'Items', 'Captured (UTC)', 'Photo'], $plan['detections']->map(fn ($d) => [
            $d->id, $d->origin, $d->count, $d->captured_at?->toDateTimeString(), $d->photo_path,
        ])->all());
        $this->table(['Alert', 'Type', 'Status', 'Raised (UTC)'], $plan['alerts']->map(fn ($a) => [
            $a->id, $a->type->value, $a->status->value, $a->created_at?->toDateTimeString(),
        ])->all());
        $this->table(['Task', 'Alert', 'Status', 'Title'], $plan['tasks']->map(fn ($t) => [
            $t->id, $t->alert_id, $t->status->value, $t->title,
        ])->all());
        $this->line(sprintf('Total: %d detections (with their photos), %d alerts, %d tasks.',
            $plan['detections']->count(), $plan['alerts']->count(), $plan['tasks']->count()));

        if ($dry) {
            $this->info('Nothing deleted (dry run).');

            return self::SUCCESS;
        }

        $files = $cleanup->runTestCamera($camera, $plan);
        $this->info(sprintf('Deleted %d detections, %d photo files, %d alerts, %d tasks.',
            $plan['detections']->count(), $files, $plan['alerts']->count(), $plan['tasks']->count()));

        return self::SUCCESS;
    }
}
