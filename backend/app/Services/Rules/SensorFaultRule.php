<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;

/**
 * sensor_fault, two signs of a broken soil sensor:
 *  - range: raw moisture <= 50 or >= 4090, or no moisture value, for 3 readings;
 *  - stuck: the raw value is exactly the same for 1 h (2 min in demo mode)
 *    while readings keep arriving (a working sensor always jitters a little).
 * Resolves after 2 normal raw values with the value moving again.
 * Checked before dry / over, which it suppresses.
 */
class SensorFaultRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::SensorFault;
    }

    public function applies(RuleContext $c): bool
    {
        return true;
    }

    public function raises(RuleContext $c): bool
    {
        return $this->outOfRange($c) || $c->stuck();
    }

    public function resolves(RuleContext $c): bool
    {
        return $c->lastAll(RuleContext::resolveCount(), fn (Reading $r) => ! RuleContext::faulty($r)) && ! $c->stuck();
    }

    public function confirmCount(RuleContext $c): int
    {
        return RuleContext::confirm();
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return AlertType::SensorFault->severity();
    }

    public function data(RuleContext $c): array
    {
        return [
            'moisture_raw' => $c->reading->moisture_raw,
            'reason' => ! $this->outOfRange($c) && $c->stuck() ? 'stuck' : 'range',
        ];
    }

    private function outOfRange(RuleContext $c): bool
    {
        return $c->lastAll(RuleContext::confirm(), fn (Reading $r) => RuleContext::faulty($r));
    }
}
