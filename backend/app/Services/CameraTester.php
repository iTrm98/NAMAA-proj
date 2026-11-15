<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Test mode: one camera frame through the litter AI at a live threshold, returned with its boxes.
 * It never writes a detection, alert or task and never touches the node. The only things kept are the
 * 2-of-3 memory of the last test frames (cache, seconds) and, on request, a snapshot in storage/app/private
 * (git-ignored). People are blurred in every frame returned or saved.
 */
class CameraTester
{
    private const PREVIOUS = 'namaa:camera:test:previous';

    private const DIR = 'test-snapshots';

    public function __construct(
        private readonly VisionClient $vision,
        private readonly ImageBlur $blur,
        private readonly LitterFilter $filter,
    ) {}

    public static function forgetPrevious(): void
    {
        Cache::forget(self::PREVIOUS);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CameraOffline|VisionUnavailable
     */
    public function frame(Node $node, float $threshold): array
    {
        try {
            $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))->get(CameraPipeline::frameUrl($node))->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new CameraOffline('The camera did not answer.', previous: $e);
        }
        if (! str_contains((string) $response->header('Content-Type'), 'image')) {
            throw new CameraOffline('The camera did not answer with an image.');
        }
        $bytes = $response->body();

        $result = $this->vision->waste($bytes, $threshold);
        try {
            $jpeg = $this->blur->blurPeople($bytes, $result['people'] ?? []);
        } catch (Throwable $e) {
            throw new VisionUnavailable('Frame could not be decoded: '.$e->getMessage(), previous: $e);
        }

        $w = (int) ($result['image_w'] ?? 0);
        $h = (int) ($result['image_h'] ?? 0);
        $items = $result['items'] ?? [];
        $previous = Cache::get(self::PREVIOUS, []);
        $filtered = $this->filter->apply($items, $node->camera_roi, $threshold, $w, $h, $previous, true);

        $boxes = [];
        foreach ($items as $item) {
            $boxes[] = $item + [
                'inside' => LitterFilter::insideRoi($item['box'] ?? [], $node->camera_roi, $w, $h),
                'passed' => in_array($item, $filtered['confirmed'], true),
            ];
        }
        $keep = max(1, (int) config('namaa.camera.persistence.window') - 1);
        Cache::put(self::PREVIOUS, array_slice(array_merge([$filtered['candidates']], $previous), 0, $keep), now()->addSeconds((int) config('namaa.camera.test.previous_ttl_seconds')));

        $payload = [
            'image' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
            'image_w' => $w,
            'image_h' => $h,
            'items' => $boxes,
            'count' => count($filtered['confirmed']),
            'total' => count($items),
            'top_confidence' => $items === [] ? null : max(array_column($items, 'confidence')),
            'inference_ms' => $result['inference_ms'] ?? null,
            'threshold' => $threshold,
            'people_count' => count($result['people'] ?? []),
            'tray_set' => $node->camera_roi !== null,
            'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        // The newest test frame, so "Snapshot" saves exactly what is on screen (blurred, never the raw photo).
        $disk = Storage::disk('local');
        $disk->put(self::DIR.'/_last.jpg', $jpeg);
        $disk->put(self::DIR.'/_last.json', json_encode(['node_id' => $node->id] + array_diff_key($payload, ['image' => 1])));

        return $payload;
    }

    /** Save the newest test frame with its boxes drawn in. Returns the stored path, null when there is no test frame yet. */
    public function snapshot(): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::DIR.'/_last.jpg') || ! $disk->exists(self::DIR.'/_last.json')) {
            return null;
        }
        $meta = json_decode((string) $disk->get(self::DIR.'/_last.json'), true) ?: [];
        $jpeg = $this->drawBoxes((string) $disk->get(self::DIR.'/_last.jpg'), $meta['items'] ?? []);

        $base = sprintf('%s/%s/%s', self::DIR, now()->format('Y-m-d'), now()->format('His_v'));
        $disk->put($base.'.jpg', $jpeg);
        $disk->put($base.'.json', json_encode($meta, JSON_PRETTY_PRINT));

        return 'storage/app/private/'.$base.'.jpg';
    }

    /** @param list<array<string, mixed>> $items */
    private function drawBoxes(string $jpeg, array $items): string
    {
        $image = @imagecreatefromstring($jpeg);
        if ($image === false) {
            return $jpeg;
        }
        $green = imagecolorallocate($image, 34, 197, 94);
        $amber = imagecolorallocate($image, 245, 158, 11);
        $grey = imagecolorallocate($image, 156, 163, 175);
        imagesetthickness($image, 3);
        foreach ($items as $item) {
            [$x1, $y1, $x2, $y2] = array_map('intval', array_pad($item['box'] ?? [], 4, 0));
            $color = ! ($item['inside'] ?? true) ? $grey : (($item['passed'] ?? false) ? $green : $amber);
            imagerectangle($image, $x1, $y1, $x2, $y2, $color);
            imagestring($image, 4, max(0, $x1), max(0, $y1 - 16), number_format((float) ($item['confidence'] ?? 0), 2), $color);
        }
        ob_start();
        imagejpeg($image, null, 88);

        return (string) ob_get_clean();
    }
}
