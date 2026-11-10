<?php

namespace App\Http\Requests\Admin;

use App\Enums\NodeType;
use App\Models\Node;
use App\Services\CameraPipeline;
use Illuminate\Validation\Validator;

/** POST /api/admin/nodes/{node}/camera-test: a url camera with a URL. */
class CameraTestRequest extends NodeRequest
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
            if ($node->type !== NodeType::Camera) {
                $v->errors()->add('node', 'Only camera nodes can be tested.');
            } elseif ($node->capture_mode !== 'url') {
                $v->errors()->add('node', 'This camera is fed by a browser, not polled by URL.');
            } elseif (CameraPipeline::urlFor($node) === '') {
                $v->errors()->add('node', 'This camera has no URL (set camera_url or CAMERA_URL).');
            }
        }];
    }
}
