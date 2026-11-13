<?php

namespace App\Http\Requests\Admin;

use App\Models\Park;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/** POST /api/admin/parks/{park}/green-zone/suggest: no body; the park must be an admin park with a boundary. */
class SuggestGreenZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('park'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            /** @var Park $park */
            $park = $this->route('park');
            if (! $park->isSatelliteEligible() || empty($park->polygon)) {
                $v->errors()->add('boundary', 'This park has no boundary to measure.');
            }
        }];
    }
}
