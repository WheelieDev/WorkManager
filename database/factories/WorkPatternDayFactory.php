<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\WorkPattern;
use App\Models\WorkPatternDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkPatternDay>
 */
class WorkPatternDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_pattern_id' => WorkPattern::factory(),
            'weekday' => fake()->randomElement(Weekday::cases()),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];
    }

    /**
     * Indicate that no hours are worked on this day.
     */
    public function notWorked(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => null,
            'end_time' => null,
        ]);
    }
}
