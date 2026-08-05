<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceState;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $slug
 * @property string $url
 * @property int $expected_status_code
 * @property string|null $expected_body
 * @property int $interval_seconds
 * @property int $timeout_seconds
 * @property int $degraded_threshold_ms
 * @property bool $is_active
 * @property bool $is_public
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
    'slug',
    'url',
    'expected_status_code',
    'expected_body',
    'interval_seconds',
    'timeout_seconds',
    'degraded_threshold_ms',
    'is_active',
    'is_public',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // A slug is the only identifier we ever expose publicly, so derive one
        // from the name whenever it is missing (uniqueness is enforced by the
        // column; collisions get a short suffix).
        static::saving(function (Service $service): void {
            if (($service->slug ?? '') !== '') {
                return;
            }

            $base = Str::slug($service->name) ?: 'service';
            $slug = $base;
            $i = 2;

            while (static::query()->where('slug', $slug)->whereKeyNot($service->getKey())->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            $service->slug = $slug;
        });
    }

    /**
     * Services that opt in to public visibility (status endpoint + page).
     *
     * @param  Builder<Service>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true);
    }

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

    /**
     * How long a service may go unchecked before its state stops being trustworthy.
     *
     * Derived from the service's own interval rather than a global constant, but floored
     * at the scheduler's tick: cron runs schedule:run once a minute, so a 30 second
     * interval is checked every 60 seconds in practice and would otherwise look stale
     * while behaving perfectly. Three ticks leaves room for one slow or skipped run
     * (monitor:run uses withoutOverlapping) before crying wolf.
     */
    public function staleAfterSeconds(): int
    {
        return max($this->interval_seconds, 60) * 3;
    }

    /**
     * Stale means "nobody has looked recently", which is not the same as down (STAT-19).
     * An unreachable host still gets checked, and that check records a failure; a service
     * only goes stale when the runner itself stopped.
     */
    public function isStaleAt(CarbonImmutable $now): bool
    {
        // A paused service is not expected to be checked, and one that has never been
        // checked already reports Unknown rather than a state that could go stale.
        if (! $this->is_active || $this->last_checked_at === null) {
            return false;
        }

        return $this->last_checked_at->addSeconds($this->staleAfterSeconds())->lessThan($now);
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
            'is_public' => 'boolean',
            'current_state' => ServiceState::class,
            'last_checked_at' => 'datetime',
            'last_response_time_ms' => 'integer',
        ];
    }
}
