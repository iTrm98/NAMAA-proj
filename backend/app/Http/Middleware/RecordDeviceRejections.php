<?php

namespace App\Http\Middleware;

use App\Models\Node;
use App\Support\DeviceRejections;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Outermost middleware (api group), acting on api/device/* only: remembers 401, 403, 422 and 429
 * answers (see DeviceRejections) so the hardware page can say why a board is
 * not getting through. Only time, status and the first message are kept.
 */
class RecordDeviceRejections
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/device/*')) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Auth and throttle failures arrive as exceptions; the handler renders them later.
            $status = match (true) {
                $e instanceof AuthenticationException => 401,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => null,
            };
            if ($status !== null) {
                $this->record($request, $status, $e instanceof AuthenticationException ? 'Unauthenticated.' : $e->getMessage());
            }

            throw $e;
        }

        $status = $response->getStatusCode();
        if (in_array($status, [401, 403, 422, 429], true)) {
            $body = json_decode((string) $response->getContent(), true);
            $message = is_array($body['errors'] ?? null)
                ? (string) (collect($body['errors'])->flatten()->first() ?? $body['message'] ?? '')
                : (string) ($body['message'] ?? '');
            $this->record($request, $status, $message);
        }

        return $response;
    }

    private function record(Request $request, int $status, string $message): void
    {
        if (! in_array($status, [401, 403, 422, 429], true)) {
            return;
        }
        // A known token is recorded under its node; an unknown one under its prefix.
        $user = $request->user() ?? PersonalAccessToken::findToken((string) $request->bearerToken())?->tokenable;

        DeviceRejections::record($user instanceof Node ? $user->id : null, $request->bearerToken(), $status, $message);
    }
}
