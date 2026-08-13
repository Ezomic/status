<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferencesRequest;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(): Response
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $subscribed = $user->subscribedServices()->pluck('services.id');

        return Inertia::render('settings/Notifications', [
            'services' => Service::query()
                ->orderByRaw('"group" is null, "group"')
                ->orderBy('name')
                ->get(['id', 'name', 'group'])
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'group' => $service->group,
                ])->values(),
            'subscribedServiceIds' => $subscribed->values(),
            // An empty set means every service, so the form opens on "all" rather than on
            // an unanswered question (STAT-42).
            'allServices' => $subscribed->isEmpty(),
        ]);
    }

    public function update(NotificationPreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // Only ever the acting user's own preference.
        $user->update(['wants_incident_mail' => $request->boolean('wants_incident_mail')]);

        $user->subscribedServices()->sync($this->subscriptions($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences updated.')]);

        return to_route('notifications.edit');
    }

    /**
     * "Every service" is stored as no rows, not as a row per service, so a service added
     * later is included without anyone revisiting this page.
     *
     * @return list<int>
     */
    private function subscriptions(NotificationPreferencesRequest $request): array
    {
        if ($request->boolean('all_services')) {
            return [];
        }

        $submitted = $request->validated('services');

        if (! is_array($submitted)) {
            return [];
        }

        $ids = [];

        foreach ($submitted as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
