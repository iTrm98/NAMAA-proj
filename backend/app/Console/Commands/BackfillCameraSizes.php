<?php

namespace App\Console\Commands;

use App\Models\Detection;
use App\Services\CameraImages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('namaa:camera:backfill-sizes {--node= : Only this camera node id}')]
#[Description('Create the display and thumbnail JPEGs for stored camera frames that lack them.')]
class BackfillCameraSizes extends Command
{
    public function handle(CameraImages $images): int
    {
        $made = 0;
        $frames = 0;
        $failed = 0;

        Detection::query()->when($this->option('node'), fn ($q, $id) => $q->where('node_id', (int) $id))
            ->orderBy('id')->chunkById(100, function ($chunk) use ($images, &$made, &$frames, &$failed) {
                foreach ($chunk as $detection) {
                    try {
                        $n = $images->backfill($detection);
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn("Frame {$detection->id}: ".$e->getMessage());

                        continue;
                    }
                    $made += $n;
                    $frames += $n > 0 ? 1 : 0;
                }
            });

        $this->info("Created {$made} files for {$frames} frames ({$failed} failed).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
