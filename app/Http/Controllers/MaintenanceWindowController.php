<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceWindowRequest;
use App\Models\MaintenanceWindow;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceWindowController extends Controller
{
    public function index(): Response
    {
        $now = CarbonImmutable::now();

        return Inertia::render('maintenance/Index', [
            'windows' => MaintenanceWindow::query()
                ->with('services:id,name')
                ->orderByDesc('starts_at')
                ->limit(50)
                ->get()
                ->map(fn (MaintenanceWindow $window): array => [
                    'id' => $window->id,
                    'description' => $window->description,
                    'starts_at' => $window->starts_at->toIso8601String(),
                    'ends_at' => $window->ends_at->toIso8601String(),
                    'services' => $window->services->pluck('name')->all(),
                    'phase' => match (true) {
                        $window->starts_at->greaterThan($now) => 'upcoming',
                        $window->ends_at->lessThan($now) => 'past',
                        default => 'open',
                    },
                ])->values(),
            'services' => Service::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'name' => $service->name,
                ])->values(),
        ]);
    }

    public function store(MaintenanceWindowRequest $request): RedirectResponse
    {
        $window = MaintenanceWindow::create([
            'description' => $request->string('description')->toString(),
            'starts_at' => $request->date('starts_at'),
            'ends_at' => $request->date('ends_at'),
        ]);

        // Built explicitly rather than passed through from validated(), which is mixed:
        // the ids reach a sync() that would happily accept nonsense.
        $serviceIds = [];

        foreach ($request->collect('service_ids') as $id) {
            if (is_numeric($id)) {
                $serviceIds[] = (int) $id;
            }
        }

        $window->services()->sync($serviceIds);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance window scheduled.')]);

        return to_route('maintenance.index');
    }

    public function destroy(MaintenanceWindow $window): RedirectResponse
    {
        $window->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance window removed.')]);

        return to_route('maintenance.index');
    }
}
