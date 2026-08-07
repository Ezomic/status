<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\BuildPublicStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PublicStatusController extends Controller
{
    /**
     * Machine-readable status for opted-in services. Leak-safe by construction: the
     * payload comes from BuildPublicStatus, the same builder the public page uses, so
     * only slug, name, state, stale and last_checked_at can ever appear here.
     */
    public function index(Request $request, BuildPublicStatus $buildPublicStatus): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Only the services key: the response shape is what ID-13 already consumes, and
        // the verdict the page needs is not part of that contract.
        return response()->json([
            'services' => $buildPublicStatus->handle(CarbonImmutable::now())['services'],
        ]);
    }

    /**
     * Either the shared token from config or a personal access token (STAT-14).
     *
     * The shared token stays supported because ID-13 already uses it, but it cannot be
     * revoked for one consumer without breaking all of them. Personal access tokens can,
     * which is the point of being able to mint them in Settings.
     */
    private function authorised(Request $request): bool
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer === '') {
            return false;
        }

        $shared = config('services.status_endpoint.token');

        if (is_string($shared) && $shared !== '' && hash_equals($shared, $bearer)) {
            return true;
        }

        $token = PersonalAccessToken::findToken($bearer);

        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return false;
        }

        // Recorded here rather than by the guard, because this endpoint authenticates the
        // token itself rather than logging a user in. Without it the "last used" column
        // in Settings would never move.
        $token->forceFill(['last_used_at' => CarbonImmutable::now()])->save();

        return true;
    }
}
