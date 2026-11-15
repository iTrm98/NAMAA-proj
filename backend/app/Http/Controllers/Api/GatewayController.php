<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GatewayIngestRequest;
use App\Jobs\ProcessGatewayEvent;
use Illuminate\Http\JsonResponse;

/** The Gateway pushes here (api.md, "Gateway"). Public route; an optional shared token guards it. */
class GatewayController extends Controller
{
    public function ingest(GatewayIngestRequest $request): JsonResponse
    {
        ProcessGatewayEvent::dispatch($request->validated('event'), $request->validated('data'));

        return response()->json(['ok' => true]);
    }
}
