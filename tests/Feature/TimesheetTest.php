<?php

use App\Enums\Weekday;
use App\Models\BankHoliday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-27 10:00');

    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);

    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '16:30',
        ]);
    }
});

it('opens on the period containing today', function () {
    Livewire::test('pages::timesheet')
        ->assertSet('period', 0)
        ->assertSee('10 Aug - 20 Sep 2026');
});

it('shows every week of the period at once', function () {
    Livewire::test('pages::timesheet')
        ->assertSeeInOrder(['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'])
        ->assertSeeInOrder(['10 Aug', '17 Aug', '24 Aug', '31 Aug', '7 Sep', '14 Sep']);
});

it('marks the week containing today', function () {
    Livewire::test('pages::timesheet')->assertSee('This week');
});

it('steps between periods', function () {
    Livewire::test('pages::timesheet')
        ->call('shiftPeriod', -1)
        ->assertSet('period', -1)
        ->assertSee('29 Jun - 9 Aug 2026')
        ->call('shiftPeriod', 1)
        ->call('shiftPeriod', 1)
        ->assertSet('period', 1)
        ->assertSee('21 Sep - 1 Nov 2026')
        ->call('thisPeriod')
        ->assertSet('period', 0);
});

it('totals each week of the period', function () {
    // Week 3 of the period is 24 to 30 August.
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();
    WorkSession::factory()->on('2026-08-25', '09:00', '16:30')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('16.50 / 30.00')
        ->assertSee('-13.50');
})->note('Expected counts Mon to Wed only, since later days have not happened.');

it('shows the sessions worked in the week', function () {
    WorkSession::factory()->on('2026-08-25', '09:00', '17:30')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('09:00')
        ->assertSee('17:30');
});

it('adds a session to a day', function () {
    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-08-25')
        ->assertSet('showingForm', true)
        ->set('start_time', '09:00')
        ->set('end_time', '17:30')
        ->call('saveSession')
        ->assertHasNoErrors()
        ->assertSet('showingForm', false);

    $session = WorkSession::query()->sole();

    expect($session->date()->toDateString())->toBe('2026-08-25')
        ->and($session->hours())->toBe(8.5);
});

it('edits an existing session', function () {
    $session = WorkSession::factory()->on('2026-08-25', '09:00', '17:00')->create();

    Livewire::test('pages::timesheet')
        ->call('editSession', $session)
        ->assertSet('start_time', '09:00')
        ->assertSet('end_time', '17:00')
        ->set('end_time', '18:00')
        ->call('saveSession')
        ->assertHasNoErrors();

    expect($session->fresh()->hours())->toBe(9.0)
        ->and(WorkSession::query()->count())->toBe(1);
});

it('deletes a session', function () {
    $session = WorkSession::factory()->on('2026-08-25')->create();

    Livewire::test('pages::timesheet')
        ->call('deleteSession', $session)
        ->assertHasNoErrors();

    expect(WorkSession::query()->count())->toBe(0);
});

it('shows each day totals on a single compact line', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('9.00 / 7.50')
        ->assertSee('+1.50')
        ->assertDontSee('Expected</dt>', escape: false);
});

it('does not count future days towards the week totals', function () {
    // Today is Thursday 27 August. Week 1 has fully elapsed and expects all
    // five weekdays; the current week expects only Monday to Thursday.
    Livewire::test('pages::timesheet')
        ->assertSee('0.00 / 37.50')
        ->assertSee('0.00 / 30.00');
})->note('Otherwise the current week reads worse than the balance does.');

it('marks a week that has not started yet', function () {
    Livewire::test('pages::timesheet')->assertSee('Not started');
});

it('shows only the days the pattern works', function () {
    // The pattern in beforeEach works Monday to Friday, so weekends are out.
    Livewire::test('pages::timesheet')
        ->assertSee('Mon')
        ->assertSee('Fri')
        ->assertDontSee('Sun');
});

it('drops a weekday the pattern does not work', function () {
    WorkPattern::query()->delete();

    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    foreach (Weekday::cases() as $weekday) {
        $worked = $weekday !== Weekday::Friday;
        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => $worked ? '09:00' : null,
            'end_time' => $worked ? '16:30' : null,
        ]);
    }

    Livewire::test('pages::timesheet')
        ->assertSee('Thu')
        ->assertDontSee('Fri');
});

it('still shows a day that has an entry on it', function () {
    // Saturday is not a working day, but time was recorded on it.
    WorkSession::factory()->on('2026-08-29', '10:00', '14:00')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('Sat')
        ->assertSee('10:00');
})->note('Otherwise recorded time would vanish from the grid.');

it('always shows today', function () {
    WorkPattern::query()->delete();

    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create(['weekday' => $weekday, 'start_time' => null, 'end_time' => null]);
    }

    // Today is Thursday 27 August and the pattern works nothing.
    Livewire::test('pages::timesheet')->assertSee('27 Aug');
});

it('still shows a bank holiday that falls on a working day', function () {
    BankHoliday::factory()->on('2026-08-31')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('31 Aug')
        ->assertSee('Summer bank holiday');
})->note('It expects no hours, but it explains why the week is short.');

it('shows every day when all days is switched on', function () {
    Livewire::test('pages::timesheet')
        ->assertDontSee('Sun')
        ->set('allDays', true)
        ->assertSee('Sun');
});

it('falls back to the working week with no pattern at all', function () {
    WorkPattern::query()->delete();

    Livewire::test('pages::timesheet')
        ->assertSee('Mon')
        ->assertDontSee('Sat');
})->note('There has to be somewhere to record time before a pattern exists.');

it('rejects an end time before the start time', function () {
    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-08-25')
        ->set('start_time', '17:00')
        ->set('end_time', '09:00')
        ->call('saveSession')
        ->assertHasErrors('end_time');

    expect(WorkSession::query()->count())->toBe(0);
});

it('shows the running balance for the period', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '16:30')->create();
    WorkSession::factory()->on('2026-08-25', '09:00', '17:30')->create();

    Livewire::test('pages::timesheet')->assertSee('Balance');
});

it('loads the whole period without a query per day', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();

    DB::enableQueryLog();
    Livewire::test('pages::timesheet');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);
})->note('42 days once cost a query each; they are now batched.');

it('warns when the balance passes the limit', function () {
    WorkPattern::query()->delete();
    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);

    Livewire::test('pages::timesheet')
        ->assertSee('Over the 16h limit')
        ->assertSee('Balance +20.00h');
});

it('requires signing in', function () {
    auth()->logout();

    $this->get(route('timesheet'))->assertRedirect(route('login'));
});
