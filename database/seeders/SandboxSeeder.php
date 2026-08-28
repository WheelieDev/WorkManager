<?php

namespace Database\Seeders;

use App\Enums\Weekday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use App\Support\FlexiPeriod;
use Illuminate\Database\Seeder;

/**
 * Fills the sandbox with a believable period so the interface has something
 * to show. Never run against the real database.
 */
class SandboxSeeder extends Seeder
{
    /**
     * The PIN for the sandbox account.
     */
    public const Pin = '000000';

    /**
     * Seed the sandbox.
     */
    public function run(): void
    {
        User::query()->create(['name' => 'Sandbox', 'pin' => self::Pin]);

        $pattern = WorkPattern::query()->create(['start_date' => '2026-01-01']);

        foreach (Weekday::cases() as $weekday) {
            $worked = $weekday !== Weekday::Friday;

            $pattern->days()->create([
                'weekday' => $weekday,
                'start_time' => $worked ? '09:00' : null,
                'end_time' => $worked ? '16:30' : null,
            ]);
        }

        // Start where the balance starts counting, so the seeded days show up
        // as surplus and deficit rather than as nothing. If tracking has not
        // begun yet, fall back to the period start so there is still something
        // on screen, even though none of it counts.
        $period = FlexiPeriod::current();
        $start = $period->countedFrom() ?? $period->start;

        if ($start->isFuture()) {
            $start = $period->start;
        }

        // A mixture of long days, short days and days off, so the ledger shows
        // bars either side of the line rather than one flat colour.
        $days = [
            [0, '09:00', '17:30'],
            [1, '09:00', '16:30'],
            [2, '09:00', '14:00'],
            [7, '08:30', '18:00'],
            [8, '09:00', '16:30'],
            [9, '09:00', '16:30'],
            [14, '09:00', '18:00'],
            [15, '09:00', '12:30'],
            [15, '13:30', '17:00'],
        ];

        foreach ($days as [$offset, $from, $to]) {
            $date = $start->copy()->addDays($offset);

            if ($date->isFuture()) {
                continue;
            }

            WorkSession::query()->create([
                'started_at' => $date->copy()->setTimeFromTimeString($from),
                'ended_at' => $date->copy()->setTimeFromTimeString($to),
            ]);
        }
    }
}
