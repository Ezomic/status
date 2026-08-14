<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckSource;
use App\Enums\ServiceState;
use Carbon\CarbonImmutable;
use Database\Factories\CheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $service_id
 * @property CheckSource $source
 * @property int|null $status_code
 * @property int $response_time_ms
 * @property bool $ok
 * @property ServiceState $state
 * @property string|null $error
 * @property CarbonImmutable $checked_at
 * @property-read Service $service
 */
#[Fillable(['service_id', 'source', 'status_code', 'response_time_ms', 'ok', 'state', 'error', 'checked_at'])]
class Check extends Model
{
    /** @use HasFactory<CheckFactory> */
    use HasFactory, MassPrunable;

    public $timestamps = false;

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Everything the app reports on today is measured from the droplet (STAT-45).
     *
     * Applied at every read rather than assumed, so the day an external source starts
     * writing here, uptime, the strip, the sparkline, the report and incident evaluation
     * all keep meaning what they meant the day before.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInternal(Builder $query): void
    {
        $query->where('source', CheckSource::Internal);
    }

    /**
     * Retention is what keeps the 60 day strip query from degrading forever.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('checked_at', '<', CarbonImmutable::now()->subDays(90));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => CheckSource::class,
            'status_code' => 'integer',
            'response_time_ms' => 'integer',
            'ok' => 'boolean',
            'state' => ServiceState::class,
            'checked_at' => 'datetime',
        ];
    }
}
