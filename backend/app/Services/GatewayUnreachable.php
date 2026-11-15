<?php

namespace App\Services;

use RuntimeException;

/** The Gateway did not answer (no URL, timeout, or an error status). */
class GatewayUnreachable extends RuntimeException
{
    /** Callers that do not catch it answer 502 gateway_unreachable. */
    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => 'gateway_unreachable'], 502);
    }
}
