<?php

namespace Database\Factories;

use App\Enums\WorkKind;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<WorkSession>
 */
class WorkSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = Carbon::parse(fake()->dateTimeBetween('-2 weeks', 'now'))->setTime(9, 0);

        return [
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(8),
            'kind' => WorkKind::Contracted,
        ];
    }

    /**
     * Place the session on a given date, running for the given hours.
     */
    public function on(string $date, string $from = '09:00', string $to = '17:00'): static
    {
        return $this->state(fn (array $attributes) => [
            'started_at' => Carbon::parse("{$date} {$from}"),
            'ended_at' => Carbon::parse("{$date} {$to}"),
        ]);
    }

    /**
     * Indicate that the session was worked as time off in lieu.
     */
    public function inLieu(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => WorkKind::Lieu,
        ]);
    }

    /**
     * Indicate that the session is still running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => null,
        ]);
    }
}
