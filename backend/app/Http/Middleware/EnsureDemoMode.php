<?php

namespace App\Http\Middleware;

use App\Support\Namaa;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Demo-only routes answer 404 when DEMO_MODE is false, as if they did not exist. */
class EnsureDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Namaa::demoMode(), 404);

        return $next($request);
    }
}
