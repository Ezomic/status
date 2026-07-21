<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PublicStatusController extends Controller
{
    /**
     * Machine-readable status for opted-in services. Deliberately leak-safe:
     * only slug, name, state and last_checked_at, never URLs, hosts, response
     * times, or incident detail.
     */
    public function index(Request $request): JsonResponse
    {
        $token = config('services.status_endpoint.token');

        if (! is_string($token) || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $services = Cache::remember('public-status', now()->addSeconds(30), function (): array {
            return Service::query()
                ->public()
                ->orderBy('name')
                ->get()
                ->map(fn (Service $service): array => [
                    'slug' => $service->slug,
                    'name' => $service->name,
                    'state' => $service->current_state->value,
                    'last_checked_at' => $service->last_checked_at?->toIso8601String(),
                ])
                ->all();
        });

        return response()->json(['services' => $services]);
    }
}
