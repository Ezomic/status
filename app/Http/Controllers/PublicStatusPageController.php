<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\BuildPublicStatus;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class PublicStatusPageController extends Controller
{
    /**
     * The shareable page (STAT-5). Deliberately outside the auth group: this is what you
     * send someone who is asking whether an app is down.
     *
     * The payload comes from BuildPublicStatus rather than ServiceController::summarise(),
     * which intentionally carries host, url and last_response_time_ms and must never
     * reach an unauthenticated response.
     */
    public function index(BuildPublicStatus $buildPublicStatus): Response
    {
        return Inertia::render('PublicStatus', $buildPublicStatus->handle(CarbonImmutable::now()));
    }
}
