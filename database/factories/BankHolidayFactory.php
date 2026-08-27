<?php

namespace Database\Factories;

use App\Models\BankHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankHoliday>
 */
class BankHolidayFactory extends Factory
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
            'title' => fake()->randomElement(['Good Friday', 'Easter Monday', 'Summer bank holiday']),
            'notes' => null,
        ];
    }

    /**
     * Place the holiday on a given date.
     */
    public function on(string $date, string $title = 'Summer bank holiday'): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
            'title' => $title,
        ]);
    }
}
