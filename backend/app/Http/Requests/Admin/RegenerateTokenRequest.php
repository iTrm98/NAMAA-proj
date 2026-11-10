<?php

namespace App\Http\Requests\Admin;

use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Validation\Validator;

/** POST /api/admin/nodes/{node}/token. */
class RegenerateTokenRequest extends NodeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            /** @var Node $node */
            $node = $this->route('node');
            if ($node->type === NodeType::Camera && $node->capture_mode === 'browser') {
                $v->errors()->add('node', 'A browser camera has no device token.');
            }
        }];
    }
}
