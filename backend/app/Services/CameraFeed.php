<?php

namespace App\Services;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Demo fallback for the camera: sample photos played as the camera's next
 * frames, one per capture interval (10 s demo), through the normal camera
 * pipeline (origin `scenario`). Photos come from data/samples/litter/ and
 * data/samples/clean/ (git-ignored: public places may show people).
 *
 *   litter scenario  -> the litter photos, in a loop (up to 60 frames)
 *   recover          -> the clean photos, in a loop (up to 36 frames: enough to
 *                       resolve the alert and verify a task)
 *   reset            -> stop
 *
 * State lives in the cache (shared by the backend and scheduler containers).
 */
class CameraFeed
{
    public const KEY = 'namaa:camera:feed';

    private const LIMITS = ['litter' => 60, 'clean' => 36];

    public static function folder(string $kind): string
    {
        return rtrim((string) (config('namaa.camera.samples_path') ?: base_path('../data/samples')), '/').'/'.$kind;
    }

    /** @return list<string> JPEG / PNG files of a sample folder, sorted by name */
    public static function photos(string $kind): array
    {
        $files = glob(self::folder($kind).'/*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE) ?: [];
        sort($files);

        return array_values($files);
    }

    /**
     * The company's first simulated (test) camera, e.g. "Sim Cam". Demo frames
     * never go to a real camera: real cameras only get frames from the capture job.
     */
    public static function cameraOf(?int $companyId): ?Node
    {
        return Node::where('type', NodeType::Camera)
            ->where('source', DataSource::Simulated->value)
            ->when($companyId, fn ($q) => $q->whereHas('park', fn ($p) => $p->where('company_id', $companyId)))
            ->orderBy('id')->first();
    }

    /** @return array<string, mixed> summary for the scenario */
    public function start(string $kind, ?int $companyId): array
    {
        $camera = self::cameraOf($companyId);
        if (! $camera) {
            throw new InvalidArgumentException('This company has no simulated camera. Run php artisan namaa:sim:camera to add "Sim Cam".');
        }

        $photos = self::photos($kind);
        if ($photos === []) {
            throw new InvalidArgumentException("No photos in data/samples/{$kind}/. Add about 10 JPEG photos of park grass "
                .($kind === 'litter' ? 'with litter on it' : 'without litter').', or use the Camera card (webcam or upload).');
        }

        Cache::forever(self::KEY, ['kind' => $kind, 'node_id' => $camera->id, 'sent' => 0, 'last' => 0.0]);

        return ['camera_node_id' => $camera->id, 'feed' => $kind, 'photos' => count($photos)];
    }

    public function stop(): void
    {
        Cache::forget(self::KEY);
    }

    /** @return array{kind: string, node_id: int, sent: int, last: float}|null */
    public function current(): ?array
    {
        return Cache::get(self::KEY);
    }

    /**
     * The next frame when one is due (one per capture interval), else null.
     *
     * @return array{node: Node, bytes: string}|null
     */
    public function next(int $everySeconds): ?array
    {
        $feed = $this->current();
        if (! $feed || now()->getTimestampMs() / 1000 - $feed['last'] < $everySeconds - 1) {
            return null;
        }

        $photos = self::photos($feed['kind']);
        $node = Node::find($feed['node_id']);
        if ($photos === [] || ! $node || $feed['sent'] >= self::LIMITS[$feed['kind']]) {
            $this->stop();

            return null;
        }

        $file = $photos[$feed['sent'] % count($photos)];
        Cache::forever(self::KEY, ['sent' => $feed['sent'] + 1, 'last' => now()->getTimestampMs() / 1000] + $feed);

        return ['node' => $node, 'bytes' => (string) file_get_contents($file)];
    }
}
