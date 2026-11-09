<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;
use App\Support\Namaa;

/**
 * blockage (field version: calibrated pump): the pump is on and water flows,
 * but below 50 % of the node's expected flow for 2 minutes (demo: 3
 * readings): a clogged filter, a kinked pipe or blocked drippers. Resolves
 * when the flow is back at or above 70 % of expected (pump on) for 2 readings.
 * Skipped for nodes without an expected flow (the real pump is not measured
 * yet). No flow at all is the no_flow rule, not this one.
 */
class BlockageRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::Blockage;
    }

    public function applies(RuleContext $c): bool
    {
        return $c->node->has_pump && ($c->node->expected_flow_l_min ?? 0) > 0 && $c->reading->flow !== null;
    }

    public function raises(RuleContext $c): bool
    {
        $low = $c->node->expected_flow_l_min * Namaa::rule('blockage_low_ratio');

        return $c->lastAll($this->confirmCount($c), fn (Reading $r) => $r->pump_on === true && RuleContext::flowing($r) && $r->flow < $low);
    }

    public function resolves(RuleContext $c): bool
    {
        $ok = $c->node->expected_flow_l_min * Namaa::rule('blockage_ok_ratio');

        return $c->lastAll(RuleContext::resolveCount(), fn (Reading $r) => $r->pump_on === true && $r->flow !== null && $r->flow >= $ok);
    }

    public function confirmCount(RuleContext $c): int
    {
        return RuleContext::blockageReadings();
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return AlertType::Blockage->severity();
    }

    public function data(RuleContext $c): array
    {
        return ['flow' => $c->reading->flow, 'expected_flow' => $c->node->expected_flow_l_min, 'pump_on' => $c->reading->pump_on];
    }
}
