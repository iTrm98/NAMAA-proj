<?php

namespace App\Http\Requests\Admin;

use App\Services\ScenarioService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/admin/demo/scenario { name } (demo mode only). */
class RunScenarioRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', Rule::in(ScenarioService::NAMES)],
            // Only for `reset`: also put the healthy parks back to the start.
            'full' => ['sometimes', 'boolean'],
        ];
    }
}
