<?php

namespace App\Services;

/**
 * Stand litter filters, applied to the items /waste returns for one frame:
 *  1. the tray area (polygon, normalized 0..1): items whose centre is outside are ignored;
 *  2. the camera's threshold: items below it are ignored;
 *  3. persistence: an item counts as litter only if it also showed in at least
 *     (needed - 1) of the previous (window - 1) frames (default: 2 of the last 3).
 *
 * "Candidates" are the items that passed 1 and 2; "confirmed" the ones that also passed 3.
 */
class LitterFilter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{0: float, 1: float}>|null  $roi  polygon with at least 3 points, null = whole frame
     * @param  list<list<array<string, mixed>>>  $previous  candidates of the previous frames, newest first
     * @return array{candidates: list<array<string, mixed>>, confirmed: list<array<string, mixed>>}
     */
    public function apply(array $items, ?array $roi, ?float $threshold, int $width, int $height, array $previous = [], bool $persist = false): array
    {
        $candidates = [];
        foreach ($items as $item) {
            if ($threshold !== null && (float) ($item['confidence'] ?? 0) < $threshold) {
                continue;
            }
            if (! self::insideRoi($item['box'] ?? [], $roi, $width, $height)) {
                continue;
            }
            $candidates[] = $item;
        }

        if (! $persist) {
            return ['candidates' => $candidates, 'confirmed' => $candidates];
        }

        $cfg = config('namaa.camera.persistence');
        $lookback = array_slice($previous, 0, max(0, (int) $cfg['window'] - 1));
        $needed = max(0, (int) $cfg['needed'] - 1); // earlier frames that must also show it
        $confirmed = array_values(array_filter($candidates, function (array $item) use ($lookback, $needed, $cfg) {
            $seen = 0;
            foreach ($lookback as $frame) {
                foreach ($frame as $old) {
                    if (self::iou($item['box'], $old['box'] ?? []) >= (float) $cfg['min_iou']) {
                        $seen++;
                        break;
                    }
                }
            }

            return $seen >= $needed;
        }));

        return ['candidates' => $candidates, 'confirmed' => $confirmed];
    }

    /** @param list<float|int> $box [x1, y1, x2, y2] in pixels */
    public static function insideRoi(array $box, ?array $roi, int $width, int $height): bool
    {
        if ($roi === null || count($roi) < 3 || count($box) < 4 || $width <= 0 || $height <= 0) {
            return true;
        }
        $x = (($box[0] + $box[2]) / 2) / $width;
        $y = (($box[1] + $box[3]) / 2) / $height;

        $inside = false;
        for ($i = 0, $j = count($roi) - 1; $i < count($roi); $j = $i++) {
            [$xi, $yi] = $roi[$i];
            [$xj, $yj] = $roi[$j];
            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-9) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** @param list<float|int> $a @param list<float|int> $b */
    public static function iou(array $a, array $b): float
    {
        if (count($a) < 4 || count($b) < 4) {
            return 0.0;
        }
        $w = min($a[2], $b[2]) - max($a[0], $b[0]);
        $h = min($a[3], $b[3]) - max($a[1], $b[1]);
        if ($w <= 0 || $h <= 0) {
            return 0.0;
        }
        $inter = $w * $h;
        $union = ($a[2] - $a[0]) * ($a[3] - $a[1]) + ($b[2] - $b[0]) * ($b[3] - $b[1]) - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }
}
