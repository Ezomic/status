<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\BuildMonthlyReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, BuildMonthlyReport $buildReport): Response
    {
        $now = CarbonImmutable::now();
        $months = $buildReport->availableMonths($now);

        return Inertia::render('Reports', [
            'months' => $months,
            'report' => $buildReport->handle(
                $buildReport->resolveMonth($request->string('month')->toString(), $now),
            ),
            'retentionDays' => 90,
        ]);
    }
}
