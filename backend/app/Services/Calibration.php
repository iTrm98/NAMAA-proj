<?php

namespace App\Services;

use App\Models\Node;

/**
 * Raw capacitive moisture (ADC 0-4095) to soil moisture %, per node.
 * The sensor reads higher when dry, so dry_value > wet_value:
 *
 *   moisture_pct = clamp((dry_value - raw) / (dry_value - wet_value) * 100, 0, 100), 1 decimal
 */
class Calibration
{
    public function moisturePct(Node $node, ?int $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $span = $node->dry_value - $node->wet_value;
        if ($span <= 0 || ! $this->isCalibrated($node)) {
            return null; // broken calibration: store the raw value only
        }

        $pct = ($node->dry_value - $raw) / $span * 100;

        return round(max(0.0, min(100.0, $pct)), 1);
    }

    /**
     * Valid span (dry above wet; equal values are not a calibration) and, on the demo stand,
     * both points recorded by the admin (nodes.calibrated_dry_at / calibrated_wet_at).
     */
    public function isCalibrated(Node $node): bool
    {
        if ($node->dry_value - $node->wet_value <= 0) {
            return false;
        }
        $attrs = $node->getAttributes();
        if (! array_key_exists('calibrated_dry_at', $attrs) || ! $node->isStand()) {
            return true;
        }

        return $node->calibrated_dry_at !== null && $node->calibrated_wet_at !== null;
    }

    /** @return array{raw: ?int, pct: ?float, calibrated: bool} raw always, pct only when calibrated */
    public function describe(Node $node, ?int $raw): array
    {
        $calibrated = $this->isCalibrated($node);

        return ['raw' => $raw, 'pct' => $calibrated ? $this->moisturePct($node, $raw) : null, 'calibrated' => $calibrated];
    }
}
