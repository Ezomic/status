<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Enums\SpikeSignalState;
use App\Models\SpikeSignal;
use App\Services\SpikeSignalWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @phpstan-type SignalData array{source: string, key: string, state: string, application: array{id: int, name: string}, route?: string|null, window_minutes: int, count: int, baseline_per_window: float|int|string, threshold: float|int|string, detected_at: string}
 */
class RecordSpikeSignal
{
    public function __construct(private readonly SpikeSignalWebhook $webhook) {}

    /**
     * Idempotent per key and state, so snag can retry freely: a repeat changes nothing and
     * announces nothing, and a signal older than what is stored is a late retry and is dropped.
     * A resolve for a key never seen is recorded but not announced, since nothing was opened.
     *
     * @param  SignalData  $data
     */
    public function handle(array $data): bool
    {
        try {
            return $this->record($data);
        } catch (UniqueConstraintViolationException) {
            return $this->record($data);
        }
    }

    /**
     * @param  SignalData  $data
     */
    private function record(array $data): bool
    {
        $state = SpikeSignalState::from($data['state']);
        $detectedAt = CarbonImmutable::parse($data['detected_at'])->utc();
        $signal = SpikeSignal::query()->where('source', $data['source'])->where('key', $data['key'])->first();

        if ($signal instanceof SpikeSignal && ($signal->state === $state || $signal->detected_at->greaterThan($detectedAt))) {
            return false;
        }

        $opening = $state === SpikeSignalState::Open;
        $announce = $opening || $signal instanceof SpikeSignal;

        $signal ??= new SpikeSignal;
        $signal->fill([
            'source' => $data['source'],
            'key' => $data['key'],
            'state' => $state,
            'application_id' => $data['application']['id'],
            'application_name' => $data['application']['name'],
            'route' => $data['route'] ?? null,
            'window_minutes' => $data['window_minutes'],
            'count' => $data['count'],
            'baseline_per_window' => (float) $data['baseline_per_window'],
            'threshold' => (float) $data['threshold'],
            'opened_at' => $opening ? $detectedAt : ($signal->opened_at ?? $detectedAt),
            'resolved_at' => $opening ? null : $detectedAt,
            'detected_at' => $detectedAt,
        ])->save();

        if ($announce) {
            $this->webhook->send($signal);
        }

        return true;
    }
}
