<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/namaa/ingest. The optional bearer token is checked by the controller (401). */
class GatewayIngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $token = (string) config('gateway.ingest_token');
        abort_unless($token === '' || hash_equals($token, (string) $this->bearerToken()), 401, 'Invalid ingest token.');

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event' => ['required', 'in:telemetry,alert'],
            'park' => ['nullable'],
            'data' => ['required', 'array'],
        ];
    }
}
