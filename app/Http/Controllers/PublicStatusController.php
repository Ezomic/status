<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\BuildPublicStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicStatusController extends Controller
{
    /**
     * Machine-readable status for opted-in services. Leak-safe by construction: the
     * payload comes from BuildPublicStatus, the same builder the public page uses, so
     * only slug, name, state, stale and last_checked_at can ever appear here.
     */
    public function index(Request $request, BuildPublicStatus $buildPublicStatus): JsonResponse
    {
        $token = config('services.status_endpoint.token');

        if (! is_string($token) || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Only the services key: the response shape is what ID-13 already consumes, and
        // the verdict the page needs is not part of that contract.
        return response()->json([
            'services' => $buildPublicStatus->handle(CarbonImmutable::now())['services'],
        ]);
    }
}
