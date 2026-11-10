<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;
use App\Support\Namaa;

/**
 * dry: moisture < moisture_min (per park: plants differ) for 2 readings in a
 * row; resolves after 2 at or above moisture_min + margin (hysteresis: a park
 * watered to exactly the minimum must not flap between ok and dry). Faulty readings break the streak, and
 * an open sensor_fault skips the rule (a broken sensor must not send a worker
 * to water). With automatic irrigation the alert is `info` internally.
 * The engine turns an open dry alert into heat_stress in place at >= 40 °C;
 * this rule then keeps refreshing and resolving that same alert.
 */
class DryRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::Dry;
    }

    public function applies(RuleContext $c): bool
    {
        return ! $c->hasOpen(AlertType::SensorFault);
    }

    public function raises(RuleContext $c): bool
    {
        $min = $c->park->moisture_min;

        return $c->lastAll($this->confirmCount($c), fn (Reading $r) => ! RuleContext::faulty($r) && $r->moisture_pct < $min);
    }

    public function resolves(RuleContext $c): bool
    {
        $recovered = RuleContext::dryRecovered($c->park);

        return $c->lastAll(RuleContext::resolveCount(), fn (Reading $r) => ! RuleContext::faulty($r) && $r->moisture_pct >= $recovered);
    }

    public function confirmCount(RuleContext $c): int
    {
        return (int) Namaa::rule('dry_confirm_readings');
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return $this->auto($c) ? AlertSeverity::Info : AlertType::Dry->severity();
    }

    public function data(RuleContext $c): array
    {
        return [
            'moisture_pct' => $c->reading->moisture_pct,
            'moisture_min' => $c->park->moisture_min,
            'temperature' => $c->reading->temperature,
            'auto_irrigation' => $this->auto($c),
        ];
    }

    private function auto(RuleContext $c): bool
    {
        return $c->park->auto_irrigation && $c->node->has_pump;
    }
}
