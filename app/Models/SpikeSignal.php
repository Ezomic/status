<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SpikeSignalState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A report-volume symptom pushed in by another app (snag, SNAG-34): the app is answering, and
 * something is still badly wrong. One row per source and key, holding the latest episode.
 *
 * @property int $id
 * @property string $source
 * @property string $key
 * @property SpikeSignalState $state
 * @property int $application_id
 * @property string $application_name
 * @property string|null $route
 * @property int $window_minutes
 * @property int $count
 * @property float $baseline_per_window
 * @property float $threshold
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $detected_at
 */
#[Fillable([
    'source', 'key', 'state', 'application_id', 'application_name', 'route', 'window_minutes',
    'count', 'baseline_per_window', 'threshold', 'opened_at', 'resolved_at', 'detected_at',
])]
class SpikeSignal extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => SpikeSignalState::class,
            'application_id' => 'integer',
            'window_minutes' => 'integer',
            'count' => 'integer',
            'baseline_per_window' => 'float',
            'threshold' => 'float',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }
}
