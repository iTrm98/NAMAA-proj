<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Enums\PumpReason;
use App\Models\Alert;
use App\Models\Node;
use App\Models\Park;
use App\Models\Reading;
use App\Services\Rules\BlockageRule;
use App\Services\Rules\DryRule;
use App\Services\Rules\LeakRule;
use App\Services\Rules\LowBatteryRule;
use App\Services\Rules\NoFlowRule;
use App\Services\Rules\OverRule;
use App\Services\Rules\Rule;
use App\Services\Rules\RuleContext;
use App\Services\Rules\SensorFaultRule;
use App\Support\Namaa;

/**
 * Runs the decision table after every stored reading (inside the ingest
 * transaction, park row locked):
 *
 *  1. the reading resolves the node's open `offline` alert;
 *  2. each rule raises, refreshes or resolves its alert (one open alert per
 *     problem: park + node + type); sensor_fault runs first and suppresses dry / over;
 *  3. no_flow raised -> pump target off (reason safety);
 *  4. heat stress: an open dry alert at >= 40 °C becomes heat_stress in
 *     place (urgent), and stays so until the moisture recovers;
 *  5. automatic irrigation (park setting): dry open -> pump on, moisture at the
 *     middle of the range -> pump off (reason rule);
 *  6. the park status is recalculated (offline > dry > over > ok).
 */
class RulesEngine
{
    /** @var list<Rule> */
    private array $rules;

    public function __construct(
        private readonly AlertService $alerts,
        private readonly ParkStatusService $status,
        private readonly PumpService $pump,
    ) {
        $this->rules = [
            new SensorFaultRule, new DryRule, new OverRule, new NoFlowRule, new LeakRule,
            new BlockageRule, new LowBatteryRule,
        ];
    }

    public function afterReading(Reading $reading, Node $node, Park $park): void
    {
        $open = Alert::open()->where('park_id', $park->id)->where('node_id', $node->id)->get()
            ->each(fn (Alert $a) => $a->setRelation('park', $park))
            ->keyBy(fn (Alert $a) => $a->type->value)->all();

        if (isset($open[AlertType::Offline->value])) {
            $this->alerts->resolve($open[AlertType::Offline->value], 'reading_received');
            unset($open[AlertType::Offline->value]);
        }

        $recent = Reading::where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(RuleContext::window())->get();

        $context = new RuleContext($park, $node, $reading, $recent, $open);

        foreach ($this->rules as $rule) {
            $this->apply($rule, $context);
        }

        $this->heatStress($context);
        $this->autoIrrigation($context);

        $this->status->recalculate($park);
    }

    private function apply(Rule $rule, RuleContext $c): void
    {
        if (! $rule->applies($c)) {
            return;
        }

        $type = $rule->type();
        // dry also owns the alert after it became heat_stress (one alert per problem).
        $alert = $type === AlertType::Dry ? $c->openDry() : ($c->open[$type->value] ?? null);

        if ($alert) {
            if ($rule->resolves($c)) {
                $this->alerts->resolve($alert);
                unset($c->open[$alert->type->value]);
            } else {
                $readings = (int) ($alert->data['readings'] ?? $rule->confirmCount($c)) + 1;
                $this->alerts->touch($alert, $rule->data($c) + $this->kept($alert) + ['readings' => $readings]);
            }

            return;
        }

        if ($rule->raises($c)) {
            $c->open[$type->value] = $this->alerts->raise(
                $c->park, $c->node, $type, $rule->severity($c), $rule->data($c) + ['readings' => $rule->confirmCount($c)],
            );

            if ($type === AlertType::NoFlow && $c->node->pump_target) {
                $this->pump->set($c->node, false, PumpReason::Safety);
            }
        }
    }

    /** Data keys that stay on a refreshed alert (e.g. the temperature that made it heat stress). @return array<string, mixed> */
    private function kept(Alert $alert): array
    {
        return array_intersect_key($alert->data ?? [], array_flip(['heat_temperature', 'heat_at']));
    }

    /**
     * An open dry alert and the latest temperature >= heat_stress_temp_c:
     * the same alert becomes heat_stress (urgent). It never drops back to dry
     * when the temperature falls; it resolves when the moisture recovers.
     */
    private function heatStress(RuleContext $c): void
    {
        $alert = $c->open[AlertType::Dry->value] ?? null;
        $temperature = $c->reading->temperature;

        if (! $alert || $temperature === null || $temperature < Namaa::rule('heat_stress_temp_c')) {
            return;
        }

        $this->alerts->convert($alert, AlertType::HeatStress, 'heat', ($alert->data ?? []) + [
            'heat_temperature' => $temperature,
            'heat_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        unset($c->open[AlertType::Dry->value]);
        $c->open[AlertType::HeatStress->value] = $alert;
    }

    /**
     * Automatic irrigation, only on a pump node of a park with the switch on.
     * It never restarts a pump that the safety cut-off or no_flow switched off:
     * that needs a person (a manual switch, or turning auto off and on).
     */
    private function autoIrrigation(RuleContext $c): void
    {
        $park = $c->park;
        $node = $c->node;
        $moisture = $c->reading->moisture_pct;

        // The demo stand's pump is manual only.
        if ($park->site_type === \App\Enums\SiteType::DemoStand || ! $park->auto_irrigation || ! $node->has_pump || $moisture === null || RuleContext::faulty($c->reading)) {
            return;
        }

        if (! $node->pump_target && $c->openDry() && $moisture < $park->moistureMid()) {
            $last = $this->pump->lastChange($node);

            if (! ($last && ! $last->target && $last->reason === PumpReason::Safety)) {
                $this->pump->set($node, true, PumpReason::Rule);
            }
        } elseif ($node->pump_target && $moisture >= $park->moistureMid()) {
            $this->pump->set($node, false, PumpReason::Rule);
        }
    }
}
