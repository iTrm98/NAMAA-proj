<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;
use App\Support\Namaa;

/**
 * low_battery (field version: solar + battery): battery below 20 % for 2
 * readings; resolves above 25 % for 2 readings. Only for nodes that send a
 * battery value (today the simulated parks; the USB-powered boards never do).
 */
class LowBatteryRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::LowBattery;
    }

    public function applies(RuleContext $c): bool
    {
        return $c->reading->battery_pct !== null;
    }

    public function raises(RuleContext $c): bool
    {
        $low = Namaa::rule('battery_low_pct');

        return $c->lastAll($this->confirmCount($c), fn (Reading $r) => $r->battery_pct !== null && $r->battery_pct < $low);
    }

    public function resolves(RuleContext $c): bool
    {
        $ok = Namaa::rule('battery_ok_pct');

        return $c->lastAll(RuleContext::resolveCount(), fn (Reading $r) => $r->battery_pct !== null && $r->battery_pct > $ok);
    }

    public function confirmCount(RuleContext $c): int
    {
        return (int) Namaa::rule('battery_confirm_readings');
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return AlertType::LowBattery->severity();
    }

    public function data(RuleContext $c): array
    {
        return ['battery_pct' => $c->reading->battery_pct];
    }
}
