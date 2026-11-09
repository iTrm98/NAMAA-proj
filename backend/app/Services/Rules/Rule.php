<?php

namespace App\Services\Rules;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;

/**
 * One row of the decision table, checked after every reading of a sensor node.
 * The engine raises when raises() and no alert of this type is open for the
 * node, resolves an open one when resolves(), and otherwise refreshes its data.
 */
interface Rule
{
    public function type(): AlertType;

    /** False = the rule is skipped for this reading (no raise, no resolve, no update). */
    public function applies(RuleContext $c): bool;

    public function raises(RuleContext $c): bool;

    public function resolves(RuleContext $c): bool;

    /** Readings in a row that raise the alert (stored as data.readings). */
    public function confirmCount(RuleContext $c): int;

    public function severity(RuleContext $c): AlertSeverity;

    /** @return array<string, mixed> the signals stored in alerts.data */
    public function data(RuleContext $c): array;
}
