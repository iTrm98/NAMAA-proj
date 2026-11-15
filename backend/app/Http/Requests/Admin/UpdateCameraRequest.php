<?php

namespace App\Http\Requests\Admin;

use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** PATCH /api/admin/nodes/{node}/camera { capture_mode?, camera_url? }. */
class UpdateCameraRequest extends NodeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capture_mode' => ['sometimes', Rule::in(['url', 'browser'])],
            'camera_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            // Tray area: polygon of [x, y] points, each 0..1 of the frame; null = the whole frame.
            'camera_roi' => ['sometimes', 'nullable', 'array', 'min:3', 'max:20'],
            'camera_roi.*' => ['array', 'size:2'],
            'camera_roi.*.*' => ['numeric', 'between:0,1'],
            'litter_threshold' => ['sometimes', 'nullable', 'numeric', 'between:0.01,0.99'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            /** @var Node $node */
            $node = $this->route('node');
            if ($node->type !== NodeType::Camera) {
                $v->errors()->add('node', 'Only camera nodes have a capture mode.');
            } elseif (! $this->hasAny(['capture_mode', 'camera_url', 'camera_roi', 'litter_threshold'])) {
                $v->errors()->add('capture_mode', 'Send capture_mode, camera_url, camera_roi or litter_threshold.');
            }
        }];
    }
}
