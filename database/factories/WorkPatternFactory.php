<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\WorkPattern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkPattern>
 */
class WorkPatternFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'start_date' => fake()->unique()->dateTimeBetween('-1 year', '+1 month')->format('Y-m-d'),
        ];
    }

    /**
     * Give the pattern a nine to five, Monday to Friday week.
     */
    public function nineToFive(): static
    {
        return $this->afterCreating(function (WorkPattern $pattern) {
            foreach (Weekday::cases() as $weekday) {
                $pattern->days()->create([
                    'weekday' => $weekday,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                ]);
            }
        });
    }
}
