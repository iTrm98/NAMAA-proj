<?php

namespace App\Http\Requests\Admin;

use App\Models\Park;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/** PATCH /api/admin/parks/{park}: thresholds. Another company's park: 404 before validation. */
class UpdateParkRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('park'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'moisture_min' => ['sometimes', 'required', 'integer', 'between:0,100'],
            'moisture_max' => ['sometimes', 'required', 'integer', 'between:0,100'],
            'auto_irrigation' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Park $park */
            $park = $this->route('park');
            $min = (int) $this->input('moisture_min', $park->moisture_min);
            $max = (int) $this->input('moisture_max', $park->moisture_max);

            if ($min >= $max) {
                $validator->errors()->add('moisture_min', 'moisture_min must be lower than moisture_max.');
            }

            if ($this->boolean('auto_irrigation') && ! $park->pumpNode()->exists()) {
                $validator->errors()->add('auto_irrigation', 'Automatic irrigation needs a park with a pump.');
            }
        }];
    }
}
