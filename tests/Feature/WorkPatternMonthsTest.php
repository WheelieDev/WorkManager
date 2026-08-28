<?php

use App\Enums\Weekday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use Livewire\Livewire;

beforeEach(function () {
    // These cover the balance mechanics themselves, so they run with no
    // tracking start; the start date has its own tests.
    config(['flexi.tracking_starts' => null]);
});

/**
 * A Monday to Friday pattern that skips the given months.
 *
 * @param  array<int, int>  $off
 */
function patternSkipping(array $off = []): WorkPattern
{
    $pattern = WorkPattern::factory()->withoutMonths($off)->create(['start_date' => '2026-01-01']);

    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '16:30',
        ]);
    }

    return $pattern;
}

it('works every month by default', function () {
    $pattern = patternSkipping();

    expect($pattern->worksMonth(8))->toBeTrue()
        ->and($pattern->monthsOff())->toBe([]);
});

it('knows which months it does not work', function () {
    $pattern = patternSkipping([8]);

    expect($pattern->worksMonth(8))->toBeFalse()
        ->and($pattern->worksMonth(9))->toBeTrue()
        ->and($pattern->monthsOff())->toBe([8]);
});

it('expects no hours in a month that is not worked', function () {
    patternSkipping([8]);

    $calculator = new FlexiCalculator;

    // Both are Mondays the pattern would otherwise work.
    expect($calculator->expectedHours(now()->parse('2026-08-24')))->toBe(0.0)
        ->and($calculator->expectedHours(now()->parse('2026-09-07')))->toBe(7.5);
});

it('leaves the balance untouched by an unworked August', function () {
    patternSkipping([8]);

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-24')))->toBe(0.0);
})->note('Not working a month you never work must not read as a deficit.');

it('still credits hours worked in a month that is off', function () {
    patternSkipping([8]);
    WorkSession::factory()->on('2026-08-24', '09:00', '13:00')->create();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-24')))->toBe(4.0);
});

it('does not treat days in an unworked month as working days', function () {
    patternSkipping([8]);

    $calculator = new FlexiCalculator;

    expect($calculator->isPatternWorkingDay(now()->parse('2026-08-24')))->toBeFalse()
        ->and($calculator->isPatternWorkingDay(now()->parse('2026-09-07')))->toBeTrue();
});

it('treats a pattern with no months recorded as working all of them', function () {
    $pattern = patternSkipping();
    $pattern->forceFill(['months' => []])->save();

    expect($pattern->fresh()->worksMonth(8))->toBeTrue();
})->note('Patterns created before months existed must keep working.');

it('empties a week that falls in an unworked month', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-27 10:00');

    patternSkipping([8]);

    // The week of 17 August is entirely in the month that is off.
    Livewire::test('pages::timesheet')
        ->call('shift', -1)
        ->assertSee('No working days this week.');
});

it('still shows today in an unworked month', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-27 10:00');

    patternSkipping([8]);

    Livewire::test('pages::timesheet')->assertSee('27 Aug');
})->note('You might still work an odd day in a month you are normally off.');

it('saves the months chosen on the form', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::patterns.form')
        ->assertSet('months', range(1, 12))
        ->set('start_date', '2026-09-01')
        ->set('months', [1, 2, 3, 4, 5, 6, 7, 9, 10, 11, 12])
        ->call('save')
        ->assertHasNoErrors();

    expect(WorkPattern::query()->sole()->monthsOff())->toBe([8]);
});

it('reloads the months of an existing pattern', function () {
    $this->actingAs(User::factory()->create());

    $pattern = patternSkipping([7, 8]);

    Livewire::test('pages::patterns.form', ['pattern' => $pattern])
        ->assertSet('months', [1, 2, 3, 4, 5, 6, 9, 10, 11, 12]);
});

it('requires at least one month', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->set('months', [])
        ->call('save')
        ->assertHasErrors('months');

    expect(WorkPattern::query()->count())->toBe(0);
});

it('lists the months off on the patterns page', function () {
    $this->actingAs(User::factory()->create());

    patternSkipping([8]);

    Livewire::test('pages::patterns.index')->assertSee('Off in Aug');
});

it('says all year when every month is worked', function () {
    $this->actingAs(User::factory()->create());

    patternSkipping();

    Livewire::test('pages::patterns.index')->assertSee('All year');
});
