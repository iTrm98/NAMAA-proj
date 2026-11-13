<?php

namespace App\Services;

/**
 * How far a satellite number can be trusted (decision-table.md, "NDVI v2"). A pure
 * function of what the vision service measured and what is known about the outline:
 * a 0-100 score from six weighted parts, then hard rules and caps (the lowest wins).
 *
 * Input keys: pure_pixels (int, pixels of the zone that are >= 90 % inside it), clear_fraction (0-1),
 * age_days (int, newest clear image), dates (int, clear dates in the composite; 0 or 1 = a single date),
 * mad (?float, spread of the dates' medians), traced (bool, geometry_source = traced and a green zone
 * was measured), verified (bool), geometry_source (osm|traced|rough_legacy), measured_on, inner_width_m (?float).
 */
class NdviConfidence
{
    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    public const NOT_MEASURABLE = 'not_measurable';

    private const CAP_LOW = 39;

    private const CAP_MEDIUM = 69;

    /**
     * @param  array<string, mixed>  $in
     * @return array{score: ?int, level: string, reasons: list<array{code: string, params: array<string, mixed>}>}
     */
    public static function evaluate(array $in): array
    {
        $pure = (int) ($in['pure_pixels'] ?? 0);
        $minPure = (int) config('namaa.ndvi.min_pure_pixels');
        if ($pure < $minPure) {
            return ['score' => null, 'level' => self::NOT_MEASURABLE, 'reasons' => [self::reason('too_small', ['pure' => $pure, 'needed' => $minPure])]];
        }

        $cf = (float) ($in['clear_fraction'] ?? 0);
        $age = (int) ($in['age_days'] ?? 0);
        $dates = max(1, (int) ($in['dates'] ?? 1));
        $mad = $in['mad'] ?? null;
        $width = isset($in['inner_width_m']) ? (float) $in['inner_width_m'] : null;
        $traced = (bool) ($in['traced'] ?? false);
        $verified = (bool) ($in['verified'] ?? false);

        $score = 30 * (0.4 + 0.6 * self::clamp(($pure - $minPure) / 22))
            + 20 * self::clamp(($cf - 0.5) / 0.4)
            + 15 * self::ageScore($age)
            + 15 * match (true) { $dates >= 4 => 1.0, $dates === 3 => 0.8, $dates === 2 => 0.6, default => 0.3 }
            + 10 * ($mad === null ? 0.5 : self::clamp((0.08 - (float) $mad) / 0.06))
            + 10 * self::geometryScore($traced, $verified, (string) ($in['geometry_source'] ?? ''), (string) ($in['measured_on'] ?? ''));

        // Caps, in the order of the decision table: the first reason is the one to show.
        $cap = null;
        $reasons = [];
        $apply = function (int $limit, string $code, array $params = []) use (&$cap, &$reasons) {
            $cap = $cap === null ? $limit : min($cap, $limit);
            $reasons[] = self::reason($code, $params);
        };
        if (! $traced) {
            $apply(self::CAP_LOW, 'untraced_outline');
        }
        if ($width !== null && $width < 20) {
            $apply(self::CAP_LOW, 'narrow', ['width' => (int) round($width)]);
        }
        if ($cf < 0.5) {
            $apply(self::CAP_LOW, 'partial_view', ['pct' => (int) round($cf * 100)]);
        }
        if ($age > 30) {
            $apply(self::CAP_LOW, 'old_image', ['days' => $age]);
        }
        if ($width !== null && $width >= 20 && $width < 30) {
            $apply(self::CAP_MEDIUM, 'narrow', ['width' => (int) round($width)]);
        }
        if ($traced && ! $verified) {
            $apply(self::CAP_MEDIUM, 'unverified_outline');
        }
        if ($cf >= 0.5 && $cf < 0.7) {
            $apply(self::CAP_MEDIUM, 'some_cloud', ['pct' => (int) round($cf * 100)]);
        }
        // Explaining only, no cap.
        if ($age > 15 && $age <= 30) {
            $reasons[] = self::reason('old_image', ['days' => $age]);
        }
        if ($dates < 3) {
            $reasons[] = self::reason('few_dates', ['n' => $dates]);
        }
        if ($mad !== null && (float) $mad >= 0.08) {
            $reasons[] = self::reason('inconsistent');
        }

        $score = (int) round($cap === null ? $score : min($score, $cap));

        return ['score' => $score, 'level' => self::level($score), 'reasons' => $reasons];
    }

    public static function level(int $score): string
    {
        return $score >= 70 ? self::HIGH : ($score >= 40 ? self::MEDIUM : self::LOW);
    }

    /** NDVI in words: sparse, moderate or dense (display only, alerts never use it). */
    public static function word(?float $ndvi): ?string
    {
        if ($ndvi === null) {
            return null;
        }
        $words = config('namaa.ndvi.words');

        return $ndvi < $words['sparse'] ? 'sparse' : ($ndvi < $words['moderate'] ? 'moderate' : 'dense');
    }

    /** True at the two levels that may raise or refresh an ndvi_drop. */
    public static function trusted(?string $level): bool
    {
        return $level === self::HIGH || $level === self::MEDIUM;
    }

    /** @param array<string, mixed> $params @return array{code: string, params: array<string, mixed>} */
    public static function reason(string $code, array $params = []): array
    {
        return ['code' => $code, 'params' => $params];
    }

    private static function ageScore(int $days): float
    {
        return match (true) {
            $days <= 7 => 1.0,
            $days <= 15 => 1.0 - 0.4 * ($days - 7) / 8,
            $days <= 30 => 0.6 * (30 - $days) / 15,
            default => 0.0,
        };
    }

    private static function geometryScore(bool $traced, bool $verified, string $source, string $measuredOn): float
    {
        if ($source === 'rough_legacy') {
            return 0.0;
        }
        if ($measuredOn === 'boundary') {
            return 0.4;
        }
        if ($traced) {
            return $verified ? 1.0 : 0.7;
        }

        return 0.6;
    }

    private static function clamp(float $x): float
    {
        return max(0.0, min(1.0, $x));
    }
}
