<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/admin/stand/pump { zone?: A|B, on: bool, seconds?: int }. Role admin is checked by the route middleware. */
class StandPumpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'on' => ['required', 'boolean'],
            'zone' => ['nullable', 'in:A,B'],
            'seconds' => ['nullable', 'integer', 'min:1', 'max:'.min(30, (int) config('namaa.stand.pump_max_seconds'))],
        ];
    }
}
