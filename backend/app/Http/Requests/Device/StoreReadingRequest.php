<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/device/readings. The node_id = token node check is in the controller (403). */
class StoreReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'node_id' => ['required', 'integer'],
            'moisture' => ['nullable', 'integer', 'between:0,4095'],
            'flow' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'temperature' => ['nullable', 'numeric', 'between:-40,85'],
            'humidity' => ['nullable', 'numeric', 'between:0,100'],
            'pump_on' => ['nullable', 'boolean'],
            'water_level' => ['nullable', 'integer', 'between:0,4095'],
            // Field version (solar + battery). The USB-powered boards never send it.
            'battery_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
