<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias "role": role:admin, role:admin|worker (or role:admin,worker).
 * The user's role must be one of the listed roles, else 403. Run after user.token.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = collect($roles)->flatMap(fn (string $r) => explode('|', $r))->map('trim')->all();
        $user = $request->user();

        abort_unless($user instanceof User && in_array($user->role->value, $allowed, true), 403, 'Your role cannot use this route.');

        return $next($request);
    }
}
