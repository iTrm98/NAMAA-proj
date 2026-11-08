<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias "user.token": the Bearer token must belong to a User and carry the
 * "user" ability. A node (device) token gets 403. Run after auth:sanctum.
 */
class EnsureUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->tokenCan('user'), 403, 'This route needs a user token.');

        return $next($request);
    }
}
