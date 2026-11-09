<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Reading;

/**
 * leak: water flows while the pump is off, for 3 readings; resolves after 2
 * readings without flow. Only for pump nodes that report a flow value.
 */
class LeakRule implements Rule
{
    public function type(): AlertType
    {
        return AlertType::Leak;
    }

    public function applies(RuleContext $c): bool
    {
        return $c->node->has_pump && $c->reading->flow !== null;
    }

    public function raises(RuleContext $c): bool
    {
        return $c->lastAll(RuleContext::confirm(), fn (Reading $r) => $r->pump_on === false && RuleContext::flowing($r));
    }

    public function resolves(RuleContext $c): bool
    {
        return $c->lastAll(RuleContext::resolveCount(), fn (Reading $r) => $r->flow !== null && ! RuleContext::flowing($r));
    }

    public function confirmCount(RuleContext $c): int
    {
        return RuleContext::confirm();
    }

    public function severity(RuleContext $c): AlertSeverity
    {
        return AlertType::Leak->severity();
    }

    public function data(RuleContext $c): array
    {
        return ['flow' => $c->reading->flow, 'pump_on' => $c->reading->pump_on];
    }
}
