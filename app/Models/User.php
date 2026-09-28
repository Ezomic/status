<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property bool $wants_incident_mail
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property string|null $id_sub
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'id_sub', 'wants_incident_mail'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Services this user wants alerts for (STAT-42).
     *
     * @return BelongsToMany<Service, $this>
     */
    public function subscribedServices(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_subscriptions');
    }

    /**
     * Who should actually receive incident mail (STAT-24).
     *
     * @param  Builder<User>  $query
     */
    public function scopeWantsIncidentMail(Builder $query): void
    {
        $query->where('wants_incident_mail', true);
    }

    /**
     * Narrow recipients to the ones who care about this service (STAT-42).
     *
     * No subscriptions at all means every service rather than none: that is what makes
     * the un-backfilled table behave like the old single boolean, and what stops a newly
     * added service from alerting nobody. Opting out of everything is the master switch's
     * job, not an empty set's.
     *
     * @param  Builder<User>  $query
     */
    public function scopeSubscribedTo(Builder $query, Service $service): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereDoesntHave('subscribedServices')
            ->orWhereHas('subscribedServices', fn (Builder $subscribed) => $subscribed
                ->whereKey($service->getKey())));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'wants_incident_mail' => 'boolean',
        ];
    }
}
