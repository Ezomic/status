<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceState;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'url' => 'https://'.fake()->unique()->domainName(),
            'expected_status_code' => 200,
            'interval_seconds' => 60,
            'timeout_seconds' => 5,
            'degraded_threshold_ms' => 1000,
            'is_active' => true,
            'current_state' => ServiceState::Unknown,
            'last_checked_at' => null,
            'last_response_time_ms' => null,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
