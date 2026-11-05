<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token of its own, apart from the read token on api/status: whoever may report a symptom
 * should not also be able to read, and the other way round. Unset disables the endpoint.
 */
class AuthenticateSignalToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('services.spike_signal.token');
        $bearer = (string) $request->bearerToken();

        if (! is_string($configured) || $configured === '' || $bearer === '' || ! hash_equals($configured, $bearer)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
