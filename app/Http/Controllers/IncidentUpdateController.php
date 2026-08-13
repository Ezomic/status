<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IncidentUpdateRequest;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncidentUpdateController extends Controller
{
    public function store(IncidentUpdateRequest $request, Incident $incident): RedirectResponse
    {
        // Associated rather than mass-assigned: user_id is deliberately not fillable, so
        // it can never be set from request data.
        $update = $incident->updates()->make($request->validated());
        $update->user()->associate($request->user());
        $update->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Update posted.')]);

        return to_route('incidents.show', $incident);
    }

    public function update(IncidentUpdateRequest $request, IncidentUpdate $update): RedirectResponse
    {
        $update->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Update saved.')]);

        return to_route('incidents.show', $update->incident_id);
    }

    public function destroy(IncidentUpdate $update): RedirectResponse
    {
        $incidentId = $update->incident_id;

        $update->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Update deleted.')]);

        return to_route('incidents.show', $incidentId);
    }

    /**
     * Acknowledging is idempotent and reversible: it records that someone is looking, so
     * an open incident reads as handled rather than merely open. It deliberately does not
     * touch resolved_at, because looking at something is not fixing it.
     */
    public function acknowledge(Request $request, Incident $incident): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $acknowledged = $incident->acknowledged_at !== null;

        $incident->forceFill([
            'acknowledged_at' => $acknowledged ? null : now(),
            'acknowledged_by_id' => $acknowledged ? null : $user->id,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $acknowledged ? __('Acknowledgement removed.') : __('Incident acknowledged.'),
        ]);

        return to_route('incidents.show', $incident);
    }
}
