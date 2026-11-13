<?php

namespace App\Http\Requests\Admin;

use App\Models\ParkClaim;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** POST /api/admin/parks/{park}/claims. Another company's park: 404 before validation. */
class StoreParkClaimRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('park'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field' => ['required', Rule::in(ParkClaim::FIELDS)],
            'value' => ['required', 'string', 'max:255'],
            'source_name' => ['required', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'source_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $exists = ParkClaim::where('park_id', $this->route('park')->id)
                ->where('field', $this->input('field'))->where('value', $this->input('value'))
                ->where('source_name', $this->input('source_name'))->exists();
            if ($exists) {
                $validator->errors()->add('value', 'This claim from this source is already recorded.');
            }
        }];
    }
}
