<?php

namespace App\Http\Middleware;

use App\Models\Node;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias "device.token": the Bearer token must belong to a Node and carry the
 * "device" ability. A user token gets 403. Run after auth:sanctum.
 */
class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $node = $request->user();

        abort_unless($node instanceof Node && $node->tokenCan('device'), 403, 'This route needs a device token.');

        return $next($request);
    }
}
