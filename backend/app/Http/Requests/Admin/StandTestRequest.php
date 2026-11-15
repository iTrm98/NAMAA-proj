<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/admin/stand/test { key }. */
class StandTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['key' => ['required', 'in:pump_a,pump_b,camera_a,camera_b,litter_a,litter_b']];
    }
}
