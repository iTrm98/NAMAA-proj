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
        if ($span <= 0) {
            return null; // broken calibration: store the raw value only
        }

        $pct = ($node->dry_value - $raw) / $span * 100;

        return round(max(0.0, min(100.0, $pct)), 1);
    }
}
