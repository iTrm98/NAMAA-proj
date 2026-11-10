<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /** GET /api/health (public): the server answers. Used by boards and the network check script. */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'time' => now()->utc()->format('Y-m-d\TH:i:s\Z')]);
    }
}
