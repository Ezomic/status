<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IncidentUpdate> */
class IncidentUpdateFactory extends Factory
{
    protected $model = IncidentUpdate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => null,
            'body' => fake()->sentence(),
            'is_published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['is_published' => true]);
    }
}
