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
     * POST /ndvi for one polygon and a date range.
     *
     * @param  array<string, mixed>  $polygon  GeoJSON Polygon, [lng, lat]
     * @return list<array{date: string, mean: float, pixels: int}>
     */
    public function ndvi(int $parkId, array $polygon, string $from, string $to): array
    {
        return $this->ndviDetailed($parkId, $polygon, $from, $to)['values'];
    }

    /**
     * Same call, plus one entry per satellite date found (kept or dropped with the reason).
     *
     * @param  array<string, mixed>  $polygon
     * @return array{values: list<array{date: string, mean: float, pixels: int}>, dates: list<array{date: string, status: string, reason?: string, mean?: float, pixels: int}>}
     */
    public function ndviDetailed(int $parkId, array $polygon, string $from, string $to): array
    {
        try {
            $body = Http::timeout((int) config('namaa.vision.ndvi_timeout_seconds'))
                ->acceptJson()
                ->post($this->url('/ndvi'), [
                    'park_id' => $parkId,
                    'polygon' => $polygon,
                    'date_from' => $from,
                    'date_to' => $to,
                    'max_cloud' => (int) config('namaa.ndvi.max_cloud'),
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $e) {
            throw new VisionUnavailable('Vision /ndvi failed: '.$e->getMessage(), previous: $e);
        }

        return ['values' => array_values($body['values'] ?? []), 'dates' => array_values($body['dates'] ?? [])];
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
