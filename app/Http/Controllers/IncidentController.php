<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Incident;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Incidents', [
            'incidents' => Incident::query()
                ->with('service:id,name')
                ->orderByRaw('resolved_at is not null')
                ->orderByDesc('started_at')
                ->limit(100)
                ->get()
                ->map(fn (Incident $incident): array => [
                    'id' => $incident->id,
                    'service' => $incident->service->name,
                    'service_id' => $incident->service_id,
                    'severity' => $incident->severity->value,
                    'reason' => $incident->reason,
                    'started_at' => $incident->started_at->toIso8601String(),
                    'resolved_at' => $incident->resolved_at?->toIso8601String(),
                ])->values(),
        ]);
    }
}
