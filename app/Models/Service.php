<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceState;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property int $expected_status_code
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property int $degraded_threshold_ms
 * @property bool $is_active
 * @property ServiceState $current_state
 * @property CarbonImmutable|null $last_checked_at
 * @property int|null $last_response_time_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Check> $checks
 * @property-read Collection<int, Incident> $incidents
 */
#[Fillable([
    'name',
    'url',
    'expected_status_code',
    'interval_seconds',
    'timeout_seconds',
    'degraded_threshold_ms',
    'is_active',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /** @return HasMany<Check, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function isDueAt(CarbonImmutable $now): bool
    {
        return $this->last_checked_at === null
            || $this->last_checked_at->addSeconds($this->interval_seconds)->lessThanOrEqualTo($now);
    }

    public function openIncident(): ?Incident
    {
        return $this->incidents()->whereNull('resolved_at')->first();
    }

    public function host(): string
    {
        return (string) preg_replace('#^www\.#', '', (string) parse_url($this->url, PHP_URL_HOST));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_status_code' => 'integer',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'degraded_threshold_ms' => 'integer',
            'is_active' => 'boolean',
            'current_state' => ServiceState::class,
            'last_checked_at' => 'datetime',
            'last_response_time_ms' => 'integer',
        ];
    }
}
