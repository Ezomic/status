<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\AssessFreshness;
use App\Actions\Monitoring\BuildResponseSparklines;
use App\Actions\Monitoring\BuildUptimeStrip;
use App\Actions\Services\SaveService;
use App\Enums\ServiceState;
use App\Http\Requests\ServiceRequest;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(
        BuildUptimeStrip $buildUptimeStrip,
        BuildResponseSparklines $buildSparklines,
        AssessFreshness $assessFreshness,
    ): Response {
        $services = Service::query()->orderBy('name')->get();
        $strips = $buildUptimeStrip->handle();
        $sparklines = $buildSparklines->handle();

        return Inertia::render('services/Index', [
            'freshness' => $assessFreshness->handle($services, CarbonImmutable::now()),
            'services' => $services->map(fn (Service $service): array => [
                ...$this->summarise($service),
                'strip' => $strips[$service->id] ?? [],
                'sparkline' => $sparklines[$service->id] ?? [],
            ])->values(),
            'openIncidents' => Incident::query()
                ->whereNull('resolved_at')
                ->with('service:id,name')
                ->get()
                ->map(fn (Incident $incident): array => [
                    'id' => $incident->id,
                    'service' => $incident->service->name,
                    'severity' => $incident->severity->value,
                    'reason' => $incident->reason,
                    'started_at' => $incident->started_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function show(Service $service, BuildUptimeStrip $buildUptimeStrip): Response
    {
        $since = CarbonImmutable::now()->subDay();

        // Latency views plot only checks that got a response: a failed connection
        // records 0ms, which would otherwise read as an impossibly fast request.
        $recent = $service->checks()
            ->where('checked_at', '>=', $since)
            ->whereNotNull('status_code')
            ->orderBy('checked_at')
            ->get(['id', 'state', 'status_code', 'response_time_ms', 'checked_at']);

        return Inertia::render('services/Show', [
            'service' => [
                ...$this->summarise($service),
                'expected_status_code' => $service->expected_status_code,
                'expected_body' => $service->expected_body,
                'interval_seconds' => $service->interval_seconds,
                'timeout_seconds' => $service->timeout_seconds,
                'degraded_threshold_ms' => $service->degraded_threshold_ms,
                'strip' => $buildUptimeStrip->handle()[$service->id] ?? [],
            ],
            'uptime' => [
                'day' => $this->uptimeSince($service, $since),
                'month' => $this->uptimeSince($service, CarbonImmutable::now()->subDays(30)),
            ],
            'responseTimes' => $recent
                ->map(fn (Check $check): array => [
                    'at' => $check->checked_at->toIso8601String(),
                    'ms' => $check->response_time_ms,
                ])->values(),
            'recentChecks' => $service->checks()
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (Check $check): array => [
                    'id' => $check->id,
                    'state' => $check->state->value,
                    'status_code' => $check->status_code,
                    'response_time_ms' => $check->response_time_ms,
                    'checked_at' => $check->checked_at->toIso8601String(),
                ])->values(),
            'incidents' => $service->incidents()
                ->orderByDesc('started_at')
                ->limit(20)
                ->get()
                ->map(fn (Incident $incident): array => $this->presentIncident($incident))
                ->values(),
        ]);
    }

    public function store(ServiceRequest $request, SaveService $saveService): RedirectResponse
    {
        $saveService->handle($request->validated());

        return back()->with('status', 'Service added.');
    }

    public function update(ServiceRequest $request, Service $service, SaveService $saveService): RedirectResponse
    {
        $saveService->handle($request->validated(), $service);

        return back()->with('status', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $service->delete();

        return to_route('services.index')->with('status', 'Service removed.');
    }

    /** @return array<string, mixed> */
    private function summarise(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'slug' => $service->slug,
            'url' => $service->url,
            'host' => $service->host(),
            'state' => $service->current_state->value,
            'state_label' => $service->current_state->label(),
            'is_active' => $service->is_active,
            'is_public' => $service->is_public,
            'is_stale' => $service->isStaleAt(CarbonImmutable::now()),
            // 0 only ever means the connection never opened, so there is no time to show.
            'last_response_time_ms' => $service->last_response_time_ms ?: null,
            'last_checked_at' => $service->last_checked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentIncident(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'severity' => $incident->severity->value,
            'reason' => $incident->reason,
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * Maintenance checks leave the ratio rather than counting either way: availability
     * is not measurable while a service is deliberately offline, and counting a window
     * as up would let a long deploy inflate the number (STAT-18).
     */
    private function uptimeSince(Service $service, CarbonImmutable $since): ?float
    {
        $measured = $service->checks()
            ->where('checked_at', '>=', $since)
            ->where('state', '!=', ServiceState::Maintenance)
            ->count();

        if ($measured === 0) {
            return null;
        }

        $down = $service->checks()
            ->where('checked_at', '>=', $since)
            ->where('state', ServiceState::Down)
            ->count();

        return round((($measured - $down) / $measured) * 100, 2);
    }
}
