<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** ?days=7|30 (1-90 accepted, default 30) and, for the municipality, ?company_id=. */
class ImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's role middleware decides
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'integer', 'between:1,90'],
            'company_id' => ['sometimes', 'integer', 'exists:companies,id'],
        ];
    }

    public function days(): int
    {
        return (int) ($this->validated('days') ?? 30);
    }
}
