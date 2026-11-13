<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The only way Laravel talks to the vision service (Python, http://vision:8001).
 * Every failure (connection, timeout, non-2xx) becomes a VisionUnavailable,
 * so callers can log it and carry on.
 */
class VisionClient
{
    private function url(string $path): string
    {
        return rtrim((string) config('namaa.vision.url'), '/').$path;
    }

    /**
     * POST /waste with one photo.
     *
     * @return array{items: list<array{label: string, confidence: float, box: list<float>}>, count: int,
     *     people: list<array{confidence: float, box: list<float>}>, model: string, inference_ms: int, image_w: int, image_h: int}
     */
    public function waste(string $jpeg): array
    {
        try {
            return Http::timeout((int) config('namaa.vision.waste_timeout_seconds'))
                ->acceptJson()
                ->attach('image', $jpeg, 'frame.jpg', ['Content-Type' => 'image/jpeg'])
                ->post($this->url('/waste'))
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            throw new VisionUnavailable('Vision /waste failed: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * POST /ndvi (v2): zonal statistics on the green zone (or the boundary), per date and as a 30-day composite.
     *
     * @param  array<string, mixed>  $boundary  GeoJSON Polygon or MultiPolygon, [lng, lat]
     * @param  array<string, mixed>|null  $greenZone
     * @return array<string, mixed>  measured_on, flags, geometry, values, dates, composite, reflectance, pixels
     */
    public function ndvi(int $parkId, array $boundary, ?array $greenZone, string $from, string $to, bool $pixels = false): array
    {
        try {
            $body = Http::timeout((int) config('namaa.vision.ndvi_timeout_seconds'))
                ->acceptJson()
                ->post($this->url('/ndvi'), [
                    'park_id' => $parkId,
                    'boundary' => $boundary,
                    'green_zone' => $greenZone,
                    'date_from' => $from,
                    'date_to' => $to,
                    'max_cloud' => (int) config('namaa.ndvi.max_cloud'),
                    'shrink_m' => (float) config('namaa.ndvi.shrink_m'),
                    'composite_days' => (int) config('namaa.ndvi.composite_days'),
                    'green_threshold' => (float) config('namaa.ndvi.green_threshold'),
                    'pixels' => $pixels,
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            throw new VisionUnavailable('Vision /ndvi failed: '.$e->getMessage(), previous: $e);
        }

        return $body + ['values' => [], 'dates' => [], 'composite' => null, 'pixels' => null, 'flags' => [], 'geometry' => []];
    }

    /**
     * POST /ndvi/suggest-zone: a DRAFT green zone from the pixels that are green on most clear dates.
     *
     * @param  array<string, mixed>  $boundary
     * @return array{draft: ?array<string, mixed>, area_m2: float, pixels: int, clear_dates: int, flags: list<string>}
     */
    public function suggestZone(array $boundary): array
    {
        $cfg = config('namaa.ndvi.suggest');
        try {
            $body = Http::timeout((int) config('namaa.vision.ndvi_timeout_seconds'))
                ->acceptJson()
                ->post($this->url('/ndvi/suggest-zone'), [
                    'boundary' => $boundary,
                    'date_to' => now('Asia/Riyadh')->toDateString(),
                    'days' => $cfg['days'],
                    'ndvi_threshold' => $cfg['ndvi_threshold'],
                    'min_clear_share' => $cfg['min_clear_share'],
                    'min_area_m2' => $cfg['min_area_m2'],
                    'max_cloud' => (int) config('namaa.ndvi.max_cloud'),
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            throw new VisionUnavailable('Vision /ndvi/suggest-zone failed: '.$e->getMessage(), previous: $e);
        }

        return [
            'draft' => $body['draft'] ?? null,
            'area_m2' => (float) ($body['area_m2'] ?? 0),
            'pixels' => (int) ($body['pixels'] ?? 0),
            'clear_dates' => (int) ($body['clear_dates'] ?? 0),
            'flags' => array_values($body['flags'] ?? []),
        ];
    }

    /** GET /health within 3 s. @return array<string, mixed>|null null when it does not answer */
    public function health(): ?array
    {
        try {
            return Http::timeout(3)->acceptJson()->get($this->url('/health'))->throw()->json();
        } catch (ConnectionException|RequestException) {
            return null;
        }
    }
}
