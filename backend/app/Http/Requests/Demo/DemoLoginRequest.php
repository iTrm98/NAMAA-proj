<?php

namespace App\Http\Requests\Demo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/auth/demo-login { role }: only the roles in config namaa.demo_accounts (admin, worker). */
class DemoLoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(array_keys(config('namaa.demo_accounts')))]];
    }
}
