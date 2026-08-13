<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MaintenanceWindowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Service> $services
 */
#[Fillable(['starts_at', 'ends_at', 'description'])]
class MaintenanceWindow extends Model
{
    /** @use HasFactory<MaintenanceWindowFactory> */
    use HasFactory;

    /** @param  Builder<MaintenanceWindow>  $query */
    public function scopeOpenAt(Builder $query, CarbonImmutable $now): void
    {
        $query->where('starts_at', '<=', $now)->where('ends_at', '>=', $now);
    }

    /** @param  Builder<MaintenanceWindow>  $query */
    public function scopeUpcomingAt(Builder $query, CarbonImmutable $now): void
    {
        $query->where('starts_at', '>', $now);
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'maintenance_window_service');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
