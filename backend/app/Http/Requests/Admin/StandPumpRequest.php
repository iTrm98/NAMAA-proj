<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/admin/stand/pump { on: bool }. Role admin is checked by the route middleware. */
class StandPumpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['on' => ['required', 'boolean']];
    }
}
