<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceState;
use App\Models\Incident;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'severity' => ServiceState::Down,
            'started_at' => CarbonImmutable::now()->subHour(),
            'resolved_at' => null,
            'reason' => 'Returned 500, expected 200',
        ];
    }

    public function degraded(): static
    {
        return $this->state(fn (): array => [
            'severity' => ServiceState::Degraded,
            'reason' => 'Responded in 2,400ms, over the 1,000ms threshold',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => ['resolved_at' => CarbonImmutable::now()->subMinutes(30)]);
    }
}
