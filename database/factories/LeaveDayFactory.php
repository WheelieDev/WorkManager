<?php

namespace Database\Factories;

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveDay>
 */
class LeaveDayFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->date(),
            'type' => LeaveType::Annual,
            'hours' => 7.5,
        ];
    }

    /**
     * Book the day as a phased return offset.
     */
    public function phasedReturn(): static
    {
        return $this->state(['type' => LeaveType::PhasedReturn]);
    }

    /**
     * Book the leave on a given date.
     */
    public function on(string $date, float $hours = 7.5): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'hours' => $hours,
        ]);
    }
}
