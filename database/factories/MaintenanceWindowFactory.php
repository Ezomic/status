<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaintenanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceWindow> */
class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'starts_at' => CarbonImmutable::now()->subHour(),
            'ends_at' => CarbonImmutable::now()->addHour(),
            'description' => 'Database upgrade',
        ];
    }

    public function upcoming(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHours(2),
        ]);
    }

    public function past(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => CarbonImmutable::now()->subDays(2),
            'ends_at' => CarbonImmutable::now()->subDays(2)->addHour(),
        ]);
    }
}
