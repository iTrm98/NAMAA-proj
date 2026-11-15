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
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * One camera frame, from the camera (CAMERA_URL/capture, or an image URL such as the camera bridge) or uploaded on the
 * demo page (file or laptop webcam). Both go through exactly the same steps:
 *
 *  1. POST /waste (litter items + person boxes);
 *  2. blur the people (the raw photo is never stored);
 *  3. save the JPEG on the private disk: camera/{node_id}/{Y-m-d}/{timestamp}.jpg,
 *     plus a display and a thumbnail variant (CameraImages);
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
        private readonly CameraImages $images,
        private readonly LitterFilter $filter,
    ) {}

    public static function cameraUrl(): string
    {
        return rtrim((string) config('namaa.camera.url'), '/');
    }

    /** The URL a camera node is polled at: its own camera_url, else CAMERA_URL; empty = none. */
    public static function urlFor(Node $node): string
    {
        if ($node->camera_url) {
            return rtrim($node->camera_url, '/');
        }

        // Zone B has its own camera (ZONE_B_CAMERA_URL), never the Zone A one.
        return $node->isGatewayZone() && $node->zone === 'B' ? (string) config('gateway.zone_b_camera_url') : self::cameraUrl();
    }

    /**
     * The URL one frame is fetched from. A URL that already points at an image file
     * (the USB camera bridge: http://host.docker.internal:8090/frame.jpg) is used as is;
     * a camera board URL gets /capture appended.
     */
    public static function frameUrl(Node $node): string
    {
        $url = self::urlFor($node);
        if ($url === '') {
            return '';
        }

        return preg_match('/\.(jpe?g|png)(\?.*)?$/i', $url) ? $url : $url.'/capture';
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
            ->where('capture_mode', 'url')->whereNull('detached_at')
            ->whereHas('park', fn ($p) => $p->whereNull('archived_at'))->orderBy('id')->get()
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
        // Test mode: the Live device page checks frames itself; the poll creates nothing.
        if (CameraMode::isTest()) {
            return null;
        }

        $first = null;
        $cameras = self::urlCameras();
        foreach ($cameras as $node) {
            try {
                $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))
                    ->get(self::frameUrl($node))
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

    /**
     * A frame for display only (the tray area picker, the camera test): people blurred, nothing stored.
     *
     * @return array{jpeg: string, image_w: int, image_h: int}
     */
    public function blurredPreview(string $bytes): array
    {
        $result = $this->vision->waste($bytes);
        try {
            $jpeg = $this->blur->blurPeople($bytes, $result['people'] ?? []);
        } catch (Throwable $e) {
            throw new VisionUnavailable('Frame could not be decoded: '.$e->getMessage(), previous: $e);
        }

        return ['jpeg' => $jpeg, 'image_w' => (int) ($result['image_w'] ?? 0), 'image_h' => (int) ($result['image_h'] ?? 0)];
    }

    public function process(Node $node, string $bytes, string $origin, ?string $sample = null): Detection
    {
        $result = $this->vision->waste($bytes);
        $people = $result['people'] ?? [];

        // Stand filters: tray area, per-camera threshold, and (polled frames only) 2 of the last 3 frames.
        $items = $result['items'] ?? [];
        $candidates = null;
        $persist = $origin === 'camera';
        if ($persist || $node->camera_roi || $node->litter_threshold !== null) {
            $previous = $persist
                ? Detection::where('node_id', $node->id)->orderByDesc('captured_at')->orderByDesc('id')
                    ->limit(max(0, (int) config('namaa.camera.persistence.window') - 1))->pluck('candidates')
                    ->map(fn ($c) => $c ?? [])->all()
                : [];
            $filtered = $this->filter->apply(
                $items, $node->camera_roi, $node->litter_threshold ?? (float) config('namaa.camera.litter_threshold'),
                (int) ($result['image_w'] ?? 0), (int) ($result['image_h'] ?? 0), $previous, $persist,
            );
            $candidates = $filtered['candidates'];
            $items = $filtered['confirmed'];
            $result['count'] = count($items);
        }

        try {
            $jpeg = $this->blur->blurPeople($bytes, $people);
        } catch (Throwable $e) {
            throw new VisionUnavailable('Frame could not be decoded: '.$e->getMessage(), previous: $e);
        }

        $capturedAt = now();
        $path = sprintf('camera/%d/%s/%s.jpg', $node->id, $capturedAt->format('Y-m-d'), $capturedAt->format('His_v'));
        $this->images->store($path, $jpeg);

        $detection = Detection::create([
            'park_id' => $node->park_id,
            'node_id' => $node->id,
            'photo_path' => $path,
            'count' => (int) ($result['count'] ?? 0),
            'items' => $items,
            'candidates' => $candidates !== null && Schema::hasColumn('detections', 'candidates') ? $candidates : null,
            'people_count' => count($people),
            'model' => $result['model'] ?? null,
            'inference_ms' => $result['inference_ms'] ?? null,
            'image_w' => $result['image_w'] ?? null,
            'image_h' => $result['image_h'] ?? null,
            'origin' => $origin,
            'sample' => $sample,
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
            'thumbnail_url' => $detection->imagePath().'?size=thumb',
            'alert_id' => $alert?->id,
        ]));

        $this->forwardLitter($node, $detection, $items);

        Log::info('camera frame', ['node' => $node->id, 'origin' => $origin, 'count' => $detection->count, 'people' => count($people)]);

        return $detection;
    }

    /** A stand zone camera tells the Gateway what it saw. Never throws. @param array<int, mixed> $items */
    private function forwardLitter(Node $node, Detection $detection, array $items): void
    {
        if (! $node->isGatewayZone()) {
            return;
        }
        $conf = collect($items)->max(fn ($i) => (float) ($i['confidence'] ?? 0)) ?? 0.0;
        app(GatewayClient::class)->forwardLitter((string) $node->zone, $detection->count, (float) $conf);

        $state = app(GatewayState::class);
        if ($state->swapLitter((string) $node->zone, $detection->count > 0) !== ($detection->count > 0)) {
            $state->push($detection->count > 0 ? 'litter_seen' : 'litter_cleared', (string) $node->zone, ['count' => $detection->count]);
        }
    }

    /**
     * Daily: delete photos older than the retention (7 days) except frames an
     * alert points to (which also keeps them for its tasks). Returns the number deleted.
     */
    public function prune(): int
    {
        $before = now()->subDays((int) config('namaa.camera.retention_days'));
        $deleted = 0;

        Detection::where('captured_at', '<', $before)
            ->whereNotIn('id', fn ($q) => $q->select('detection_id')->from('alerts')->whereNotNull('detection_id'))
            ->orderBy('id')
            ->chunkById(200, function ($frames) use (&$deleted) {
                foreach ($frames as $frame) {
                    $this->images->delete($frame);
                    $frame->delete();
                    $deleted++;
                }
            });

        return $deleted;
    }
}
