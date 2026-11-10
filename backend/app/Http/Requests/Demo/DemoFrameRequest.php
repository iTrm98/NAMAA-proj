<?php

namespace App\Http\Requests\Demo;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/admin/demo/frame (multipart, demo mode only): a photo for one of
 * the admin's company's camera nodes, from a file or the laptop webcam.
 */
class DemoFrameRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'image' => ['required', 'file', 'mimes:jpeg,png', 'max:5120'],
            'node_id' => [
                'required', 'integer',
                Rule::exists('nodes', 'id')->where('type', NodeType::Camera->value)
                    ->whereIn('park_id', fn ($q) => $q->select('id')->from('parks')->where('company_id', $companyId)),
            ],
            // file = picked on the demo page, webcam = the laptop camera, scenario = sample photos.
            'via' => ['sometimes', Rule::in(['file', 'webcam'])],
        ];
    }

    /** Demo frames go to simulated (test) cameras only; a real camera gets frames from the capture job. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('node_id')) {
                return;
            }
            $node = Node::find($this->integer('node_id'));
            if ($node && $node->source === DataSource::Real) {
                $validator->errors()->add('node_id', sprintf(
                    '%s is a real camera: it only receives frames from the capture job. Send demo frames to a simulated camera (Sim Cam).',
                    $node->name,
                ));
            }
        }];
    }
}
