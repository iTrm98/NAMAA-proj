<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/admin/stand/camera-mode { mode: "test" | "demo" }. Role admin is checked by the route middleware. */
class StandCameraModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mode' => ['required', Rule::in(['test', 'demo'])]];
    }
}
