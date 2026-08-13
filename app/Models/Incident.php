<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceState;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $service_id
 * @property ServiceState $severity
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $acknowledged_by_id
 * @property string $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Service $service
 * @property-read User|null $acknowledgedBy
 * @property-read Collection<int, IncidentUpdate> $updates
 */
#[Fillable(['service_id', 'severity', 'started_at', 'resolved_at', 'reason'])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** @return HasMany<IncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => ServiceState::class,
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
