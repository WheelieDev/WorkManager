<?php

namespace Database\Factories;

use App\Models\LeaveCarryover;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveCarryover>
 */
class LeaveCarryoverFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leave_year' => 2026,
            'hours' => 8.0,
        ];
    }
}
