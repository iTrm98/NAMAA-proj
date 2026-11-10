<?php

namespace App\Http\Requests\Admin;

use App\Enums\NodeType;
use App\Models\Node;
use App\Services\OfflineChecker;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /api/admin/nodes/{node}/calibration { point: dry|wet }. */
class CalibrateNodeRequest extends NodeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['point' => ['required', Rule::in(['dry', 'wet'])]];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->has('point')) {
                return;
            }
            /** @var Node $node */
            $node = $this->route('node');
            $node->load('latestReading');
            $raw = $node->latestReading?->moisture_raw;
            $fresh = $node->last_seen_at && $node->last_seen_at->gt(now()->subSeconds(OfflineChecker::offlineSeconds(NodeType::Sensor)));

            if ($node->type !== NodeType::Sensor) {
                $v->errors()->add('point', 'Only sensor nodes have a soil calibration.');
            } elseif ($raw === null) {
                $v->errors()->add('point', 'This node has not sent a raw moisture value yet.');
            } elseif (! $fresh) {
                $v->errors()->add('point', 'This node is offline: its latest raw value is stale.');
            } else {
                $dry = $this->input('point') === 'dry' ? $raw : $node->dry_value;
                $wet = $this->input('point') === 'wet' ? $raw : $node->wet_value;
                if ($dry <= $wet) {
                    $v->errors()->add('point', 'The dry value must stay above the wet value.');
                }
            }
        }];
    }
}
