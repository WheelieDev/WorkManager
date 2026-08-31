<?php

namespace Database\Factories;

use App\Models\WorkArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkArea>
 */
class WorkAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'archived_at' => null,
        ];
    }

    /**
     * Indicate that the area has been put away.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }
}
