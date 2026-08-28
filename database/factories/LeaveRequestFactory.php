<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-10',
        ];
    }

    /**
     * Set the dates asked for.
     */
    public function between(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_on' => $from,
            'ends_on' => $to,
        ]);
    }
}
