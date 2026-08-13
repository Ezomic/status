<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentUpdate;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Incidents', [
            'incidents' => Incident::query()
                ->with('service:id,name')
                ->withCount('updates')
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
                    'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
                    'update_count' => $incident->updates_count,
                ])->values(),
        ]);
    }

    public function show(Incident $incident): Response
    {
        $incident->load(['service:id,name', 'acknowledgedBy:id,name']);

        return Inertia::render('incidents/Show', [
            'incident' => [
                'id' => $incident->id,
                'service' => $incident->service->name,
                'service_id' => $incident->service_id,
                'severity' => $incident->severity->value,
                'severity_label' => $incident->severity->label(),
                'reason' => $incident->reason,
                'started_at' => $incident->started_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
                'acknowledged_by' => $incident->acknowledgedBy?->name,
            ],
            'updates' => $incident->updates()
                ->with('user:id,name')
                ->orderBy('created_at')
                ->get()
                ->map(fn (IncidentUpdate $update): array => [
                    'id' => $update->id,
                    'body' => $update->body,
                    'is_published' => $update->is_published,
                    'author' => $update->user?->name,
                    'created_at' => $update->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }
}
