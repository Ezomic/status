<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceState;
use App\Models\Check;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Check> */
class CheckFactory extends Factory
{
    protected $model = Check::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'status_code' => 200,
            'response_time_ms' => fake()->numberBetween(40, 400),
            'ok' => true,
            'state' => ServiceState::Up,
            'error' => null,
            'checked_at' => CarbonImmutable::now(),
        ];
    }

    public function up(): static
    {
        return $this->state(fn (): array => [
            'status_code' => 200,
            'ok' => true,
            'state' => ServiceState::Up,
            'error' => null,
        ]);
    }

    public function degraded(): static
    {
        return $this->state(fn (): array => [
            'status_code' => 200,
            'response_time_ms' => 2400,
            'ok' => true,
            'state' => ServiceState::Degraded,
            'error' => null,
        ]);
    }

    public function down(): static
    {
        return $this->state(fn (): array => [
            'status_code' => 500,
            'ok' => false,
            'state' => ServiceState::Down,
            'error' => null,
        ]);
    }

    public function unreachable(): static
    {
        return $this->state(fn (): array => [
            'status_code' => null,
            'response_time_ms' => 5000,
            'ok' => false,
            'state' => ServiceState::Down,
            'error' => 'Connection timed out',
        ]);
    }
}
