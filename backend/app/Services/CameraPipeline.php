<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Events\DetectionCreated;
use App\Http\Resources\DetectionResource;
use App\Models\Alert;
use App\Models\Detection;
use App\Models\Node;
use App\Support\Realtime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One camera frame, from the camera (CAMERA_URL/capture) or uploaded on the
 * demo page (file or laptop webcam). Both go through exactly the same steps:
 *
 *  1. POST /waste (litter items + person boxes);
 *  2. blur the people (the raw photo is never stored);
 *  3. save the JPEG on the private disk: camera/{node_id}/{Y-m-d}/{timestamp}.jpg;
 *  4. a detections row, the camera node's last_seen_at;
 *  5. the litter rules; broadcast detection.created.
 *
 * The camera or the vision service failing throws VisionUnavailable: no
 * detection row, no stored photo.
 */
class CameraPipeline
{
    public function __construct(
        private readonly VisionClient $vision,
        private readonly ImageBlur $blur,
        private readonly LitterRules $litter,
        private readonly AlertService $alerts,
    ) {}

    public static function cameraUrl(): string
    {
        return rtrim((string) config('namaa.camera.url'), '/');
    }

    /** The URL a camera node is polled at: its own camera_url, else CAMERA_URL; empty = none. */
    public static function urlFor(Node $node): string
    {
        return $node->camera_url ? rtrim($node->camera_url, '/') : self::cameraUrl();
    }

    /**
     * Real camera nodes the server polls (capture_mode url, with a URL). A browser camera is
     * fed by its tab; a simulated one (Sim Cam) only gets demo frames.
     *
     * @return \Illuminate\Support\Collection<int, Node>
     */
    public static function urlCameras(): \Illuminate\Support\Collection
    {
        return Node::where('type', NodeType::Camera)->where('source', DataSource::Real->value)
            ->where('capture_mode', 'url')->orderBy('id')->get()
            ->filter(fn (Node $n) => self::urlFor($n) !== '')->values();
    }

    public static function enabled(): bool
    {
        return self::urlCameras()->isNotEmpty();
    }

    /** The first polled camera node (Model A's park). */
    public static function cameraNode(): ?Node
    {
        return self::urlCameras()->first();
    }

    /** GET {url}/capture for every polled camera and process it. Returns the first detection, null when none is polled. */
    public function capture(): ?Detection
    {
        $first = null;
        $cameras = self::urlCameras();
        foreach ($cameras as $node) {
            try {
                $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))
                    ->get(self::urlFor($node).'/capture')
                    ->throw();
            } catch (ConnectionException|RequestException $e) {
                if ($cameras->count() === 1) {
                    throw new VisionUnavailable('Camera did not answer: '.$e->getMessage(), previous: $e);
                }

                continue;
            }

            $detection = $this->process($node, $response->body(), 'camera');
            $first ??= $detection;
        }

        return $first;
    }

    public function process(Node $node, string $bytes, string $origin): Detection
    {
        $result = $this->vision->waste($bytes);
        $people = $result['people'] ?? [];

        try {
            $jpeg = $this->blur->blurPeople($bytes, $people);
        } catch (Throwable $e) {
            throw new VisionUnavailable('Frame could not be decoded: '.$e->getMessage(), previous: $e);
        }

        $capturedAt = now();
        $path = sprintf('camera/%d/%s/%s.jpg', $node->id, $capturedAt->format('Y-m-d'), $capturedAt->format('His_v'));
        Storage::disk(config('namaa.camera.disk'))->put($path, $jpeg);

        $detection = Detection::create([
            'park_id' => $node->park_id,
            'node_id' => $node->id,
            'photo_path' => $path,
            'count' => (int) ($result['count'] ?? 0),
            'items' => $result['items'] ?? [],
            'people_count' => count($people),
            'model' => $result['model'] ?? null,
            'inference_ms' => $result['inference_ms'] ?? null,
            'image_w' => $result['image_w'] ?? null,
            'image_h' => $result['image_h'] ?? null,
            'origin' => $origin,
            'captured_at' => $capturedAt,
        ]);

        $node->forceFill(['last_seen_at' => $capturedAt, 'last_litter_count' => $detection->count])->save();
        $detection->setRelation('node', $node);

        // A frame is the camera's "reading": it ends the camera's offline alert.
        Alert::open()->where('node_id', $node->id)->where('type', AlertType::Offline)->with('park')->get()
            ->each(fn (Alert $offline) => $this->alerts->resolve($offline, 'frame_received'));

        $alert = $this->litter->afterFrame($detection);

        $park = $node->park;
        Realtime::send(new DetectionCreated($park->company_id, [
            'park_id' => $park->id,
            'node_id' => $node->id,
            'detection' => (new DetectionResource($detection))->resolve(),
            'count' => $detection->count,
            'thumbnail_url' => $detection->imagePath(),
            'alert_id' => $alert?->id,
        ]));

        Log::info('camera frame', ['node' => $node->id, 'origin' => $origin, 'count' => $detection->count, 'people' => count($people)]);

        return $detection;
    }

    /**
     * Daily: delete photos older than the retention (7 days) except frames an
     * alert points to (which also keeps them for its tasks). Returns the number deleted.
     */
    public function prune(): int
    {
        $before = now()->subDays((int) config('namaa.camera.retention_days'));
        $disk = Storage::disk(config('namaa.camera.disk'));
        $deleted = 0;

        Detection::where('captured_at', '<', $before)
            ->whereNotIn('id', fn ($q) => $q->select('detection_id')->from('alerts')->whereNotNull('detection_id'))
            ->orderBy('id')
            ->chunkById(200, function ($frames) use ($disk, &$deleted) {
                foreach ($frames as $frame) {
                    $disk->delete($frame->photo_path);
                    $frame->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }
}
