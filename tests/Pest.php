<?php

use App\Enums\Weekday;
use App\Models\WorkPattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A part time pattern: Monday to Thursday, 09:00 to 13:00, August off.
 */
function partTimePattern(string $startDate = '2026-01-01'): WorkPattern
{
    $pattern = WorkPattern::factory()->withoutMonths([8])->create(['start_date' => $startDate]);

    foreach (Weekday::cases() as $weekday) {
        $worked = $weekday !== Weekday::Friday;

        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => $worked ? '09:00' : null,
            'end_time' => $worked ? '13:00' : null,
        ]);
    }

    return $pattern;
}
