<?php

namespace App\Http\Requests\Device;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/** POST /api/device/readings. The node_id = token node check is in the controller (403). */
class StoreReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The stand firmware sends moisture_raw and link; they are the same fields as moisture and via. */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'moisture' => $this->has('moisture') ? null : $this->input('moisture_raw'),
            'via' => $this->has('via') ? null : $this->input('link'),
        ], fn ($v) => $v !== null));
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
            // What the board says about itself (Sprint 4).
            'rssi' => ['nullable', 'integer', 'between:-127,0'],
            'uptime_s' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'fw_version' => ['nullable', 'string', 'max:32'],
            'via' => ['nullable', Rule::in(['wifi', 'usb'])],
            // Demo stand: true on the first report after a boot / reset (the server then clears the pump target).
            'boot' => ['nullable', 'boolean'],
            // A buffered reading: when it was measured (UTC). Not in the future, at most 24 h old.
            'recorded_at' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    $at = Carbon::parse($value);
                } catch (Throwable) {
                    $fail('The recorded_at field must be a valid UTC date and time.');

                    return;
                }
                // 60 s of tolerance for a board clock that runs a little fast.
                if ($at->gt(now()->addSeconds(60))) {
                    $fail('The recorded_at field must not be in the future.');
                } elseif ($at->lt(now()->subHours(24))) {
                    $fail('The recorded_at field must not be older than 24 hours.');
                }
            }],
        ];
    }
}
