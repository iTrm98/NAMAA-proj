<?php

namespace App\Http\Requests\Admin;

use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Validation\Validator;

/** POST /api/admin/nodes/{node}/frame (multipart): a frame from the browser camera feed. */
class BrowserFrameRequest extends NodeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['image' => ['required', 'file', 'mimes:jpeg,png', 'max:5120']];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            /** @var Node $node */
            $node = $this->route('node');
            if ($node->type !== NodeType::Camera || $node->capture_mode !== 'browser') {
                $v->errors()->add('node_id', "{$node->name} is not a browser camera: switch its capture mode to browser first.");
            }
        }];
    }
}
