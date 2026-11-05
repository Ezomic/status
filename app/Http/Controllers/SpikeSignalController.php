<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Monitoring\RecordSpikeSignal;
use App\Http\Requests\SpikeSignalRequest;
use Illuminate\Http\JsonResponse;

class SpikeSignalController extends Controller
{
    public function store(SpikeSignalRequest $request, RecordSpikeSignal $action): JsonResponse
    {
        /** @var array{source: string, key: string, state: string, application: array{id: int, name: string}, route?: string|null, window_minutes: int, count: int, baseline_per_window: float|int|string, threshold: float|int|string, detected_at: string} $data */
        $data = $request->validated();

        return response()->json(['recorded' => $action->handle($data)], 202);
    }
}
