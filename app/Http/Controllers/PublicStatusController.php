<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceState;
use App\Models\Service;
use Carbon\CarbonImmutable;
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
            $now = CarbonImmutable::now();

            return Service::query()
                ->public()
                ->orderBy('name')
                ->get()
                ->map(function (Service $service) use ($now): array {
                    $stale = $service->isStaleAt($now);

                    return [
                        'slug' => $service->slug,
                        'name' => $service->name,
                        // A frozen state must not be served as current (STAT-19). If the
                        // runner stopped, the honest answer is that we do not know, so
                        // consumers cannot be fooled into rendering a stale green.
                        'state' => $stale
                            ? ServiceState::Unknown->value
                            : $service->current_state->value,
                        'stale' => $stale,
                        'last_checked_at' => $service->last_checked_at?->toIso8601String(),
                    ];
                })
                ->all();
        });

        return response()->json(['services' => $services]);
    }
}
