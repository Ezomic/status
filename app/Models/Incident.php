<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceState;
use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $service_id
 * @property ServiceState $severity
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $resolved_at
 * @property string $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Service $service
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => ServiceState::class,
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
