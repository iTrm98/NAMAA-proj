<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/admin/stand/test-frame?threshold=0.185 (0.10..0.50). Role admin is checked by the route middleware. */
class StandTestFrameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['threshold' => ['nullable', 'numeric', 'between:0.1,0.5']];
    }
}
