<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;

/**
 * no_flow: the board reports the pump on and no flow for 3 readings (a dry
 * run: blocked pipe, empty tank). Resolves when water flows or the pump is
 * off. The engine also switches the pump target off (reason safety).
 * Only for pump nodes that report a flow value.
 */
class NoFlowRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::NoFlow;
    }

    public function applies(RuleContext $c): bool
    {
        return $c->node->has_pump && $c->reading->flow !== null;
    }

    public function raises(RuleContext $c): bool
    {
        return $c->lastAll(RuleContext::confirm(), fn (Reading $r) => $r->pump_on === true && $r->flow !== null && ! RuleContext::flowing($r));
    }

    public function resolves(RuleContext $c): bool
    {
        return $c->reading->pump_on !== true || RuleContext::flowing($c->reading);
    }

    public function confirmCount(RuleContext $c): int
    {
        return RuleContext::confirm();
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return AlertType::NoFlow->severity();
    }

    public function data(RuleContext $c): array
    {
        return ['flow' => $c->reading->flow, 'pump_on' => $c->reading->pump_on];
    }
}
