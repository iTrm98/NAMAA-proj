<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;
use App\Support\Namaa;

/**
 * over: the soil is too wet because of our irrigation.
 *  - Nodes with a pump or a flow value: moisture > moisture_max while
 *    irrigating (pump_on or flow), for 3 readings. Wet soil without
 *    irrigation (rain, a neighbour) is not the operator's fault.
 *  - Nodes without pump and flow sensor (Model B): moisture > moisture_max
 *    for 6 readings.
 * Resolves after 2 readings at or below moisture_max - margin (hysteresis). Skipped while sensor_fault is open.
 */
class OverRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::Over;
    }

    public function applies(RuleContext $c): bool
    {
        return ! $c->hasOpen(AlertType::SensorFault);
    }

    public function raises(RuleContext $c): bool
    {
        $max = $c->park->moisture_max;
        $irrigationNode = $this->irrigationNode($c);

        return $c->lastAll($this->confirmCount($c), fn (Reading $r) => ! RuleContext::faulty($r)
            && $r->moisture_pct > $max
            && (! $irrigationNode || RuleContext::irrigating($r)));
    }

    public function resolves(RuleContext $c): bool
    {
        $recovered = RuleContext::overRecovered($c->park);

        return $c->lastAll(RuleContext::resolveCount(), fn (Reading $r) => ! RuleContext::faulty($r) && $r->moisture_pct <= $recovered);
    }

    public function confirmCount(RuleContext $c): int
    {
        return $this->irrigationNode($c) ? RuleContext::confirm() : (int) Namaa::rule('over_no_pump_confirm_readings');
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return AlertType::Over->severity();
    }

    public function data(RuleContext $c): array
    {
        return [
            'moisture_pct' => $c->reading->moisture_pct,
            'moisture_max' => $c->park->moisture_max,
            'pump_on' => $c->reading->pump_on,
            'flow' => $c->reading->flow,
        ];
    }

    /** A node that can tell whether it is irrigating: it has a pump or reports a flow value. */
    private function irrigationNode(RuleContext $c): bool
    {
        return $c->node->has_pump || $c->reading->flow !== null;
    }
}
