<?php

namespace App\Http\Requests\Admin;

use App\Enums\DataSource;
use App\Models\Node;
use Illuminate\Validation\Validator;

/** POST /api/admin/nodes/{node}/relay-test { confirm }. A real pump of a real park needs confirm: true. */
class RelayTestRequest extends NodeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => ['sometimes', 'boolean']];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            /** @var Node $node */
            $node = $this->route('node');
            $node->loadMissing('park');

            if (! $node->has_pump) {
                $v->errors()->add('node', 'This node has no pump.');
            } elseif ($node->source === DataSource::Simulated) {
                $v->errors()->add('node', 'A simulated node has no relay to test.');
            } elseif ($node->pump_target) {
                $v->errors()->add('node', 'The pump is already on.');
            } elseif (! $node->park->is_test && ! $this->boolean('confirm')) {
                $v->errors()->add('confirm', 'Switching a real pump needs confirmation.');
            }
        }];
    }
}
