<?php

use App\Enums\Weekday;
use App\Models\BankHoliday;
use App\Models\LeaveDay;
use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    // These cover the balance mechanics themselves, so they run with no
    // tracking start; the start date has its own tests.
    config(['flexi.tracking_starts' => null]);
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

/*
|--------------------------------------------------------------------------
| Views and navigation
|--------------------------------------------------------------------------
*/

it('opens on this week', function () {
    Livewire::test('pages::timesheet')
        ->assertSet('mode', 'week')
        ->assertSet('focus', '2026-08-27')
        ->assertSee('Week 3 of 6')
        ->assertSee('24 Aug');
});

it('steps a day at a time in the day view', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'day')
        ->assertSee('Thursday 27 August 2026')
        ->call('shift', -1)
        ->assertSet('focus', '2026-08-26')
        ->assertSee('Wednesday 26 August 2026')
        ->call('shift', 1)
        ->call('shift', 1)
        ->assertSet('focus', '2026-08-28');
});

it('steps a week at a time in the week view', function () {
    Livewire::test('pages::timesheet')
        ->call('shift', -1)
        ->assertSet('focus', '2026-08-20')
        ->assertSee('Week 2 of 6')
        ->call('shift', 1)
        ->assertSee('Week 3 of 6');
});

it('steps a period at a time in the period view', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'period')
        ->assertSee('10 Aug - 20 Sep 2026')
        ->call('shift', -1)
        ->assertSee('29 Jun - 9 Aug 2026')
        ->call('shift', 1)
        ->call('shift', 1)
        ->assertSee('21 Sep - 1 Nov 2026');
});

it('returns to today from any view', function () {
    Livewire::test('pages::timesheet')
        ->call('shift', -2)
        ->call('today')
        ->assertSet('focus', '2026-08-27');
});

it('keeps the focus date when the view changes', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'day')
        ->call('shift', -8)
        ->assertSet('focus', '2026-08-19')
        ->call('showMode', 'week')
        ->assertSee('Week 2 of 6')
        ->call('showMode', 'period')
        ->assertSee('10 Aug - 20 Sep 2026');
})->note('Moving between views should never lose your place.');

it('ignores an unknown view', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'nonsense')
        ->assertSet('mode', 'week');
});

it('keeps the view and the date in the url', function () {
    Livewire::withUrlParams(['mode' => 'period', 'focus' => '2026-09-25'])
        ->test('pages::timesheet')
        ->assertSet('mode', 'period')
        ->assertSee('21 Sep - 1 Nov 2026');
});

it('opens a day from the week view', function () {
    Livewire::test('pages::timesheet')
        ->call('showDay', '2026-08-25')
        ->assertSet('mode', 'day')
        ->assertSet('focus', '2026-08-25')
        ->assertSee('Tuesday 25 August 2026');
});

it('opens a week from the period view', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'period')
        ->call('showWeek', '2026-08-17')
        ->assertSet('mode', 'week')
        ->assertSee('Week 2 of 6');
});

/*
|--------------------------------------------------------------------------
| Day view
|--------------------------------------------------------------------------
*/

it('lists the entries for a single day', function () {
    WorkSession::factory()->on('2026-08-27', '09:00', '12:30')->create();
    WorkSession::factory()->on('2026-08-27', '13:30', '17:00')->create();

    Livewire::test('pages::timesheet')
        ->call('showMode', 'day')
        ->assertSee('09:00')
        ->assertSee('12:30')
        ->assertSee('3h 30m')
        ->assertSee('Add entry');
});

it('says when a day has nothing on it', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'day')
        ->assertSee('Nothing recorded on this day.');
});

it('shows the bank holiday on the day it falls', function () {
    BankHoliday::factory()->on('2026-08-31')->create();

    Livewire::test('pages::timesheet')
        ->call('showDay', '2026-08-31')
        ->assertSee('Summer bank holiday')
        ->assertSee('0h / 0h');
});

/*
|--------------------------------------------------------------------------
| Week view
|--------------------------------------------------------------------------
*/

it('shows all the working days of one week only', function () {
    Livewire::test('pages::timesheet')
        ->assertSee('24 Aug')
        ->assertSee('28 Aug')
        ->assertDontSee('17 Aug')
        ->assertDontSee('31 Aug');
})->note('The week view is one week, not the whole period.');

it('shows only the days the pattern works', function () {
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

    Livewire::test('pages::timesheet')->assertSee('27 Aug');
});

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

it('does not count future days towards the week totals', function () {
    // Today is Thursday 27 August, so Friday of this week is still to come.
    Livewire::test('pages::timesheet')
        ->assertSee('0h / 30h')
        ->call('shift', -2)
        ->assertSee('0h / 37h 30m');
})->note('Otherwise the current week reads worse than the balance does.');

it('still shows a bank holiday that falls on a working day', function () {
    BankHoliday::factory()->on('2026-08-31')->create();

    Livewire::test('pages::timesheet')
        ->call('shift', 1)
        ->assertSee('31 Aug')
        ->assertSee('Summer bank holiday');
})->note('It expects no hours, but it explains why the week is short.');

/*
|--------------------------------------------------------------------------
| Period view
|--------------------------------------------------------------------------
*/

it('shows every week of the period', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'period')
        ->assertSeeInOrder(['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'])
        ->assertSee('Choose a week to see its days.')
        ->assertDontSee('Mon');
});

it('totals each week of the period', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();

    Livewire::test('pages::timesheet')
        ->call('showMode', 'period')
        ->assertSee('9h / 30h')
        ->assertSee('-21h');
});

it('marks a week that has not started yet', function () {
    Livewire::test('pages::timesheet')
        ->call('showMode', 'period')
        ->assertSee('Not started');
});

/*
|--------------------------------------------------------------------------
| Entries
|--------------------------------------------------------------------------
*/

it('adds an entry to a day', function () {
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

it('edits an existing entry', function () {
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

it('deletes an entry', function () {
    $session = WorkSession::factory()->on('2026-08-25')->create();

    Livewire::test('pages::timesheet')
        ->call('deleteSession', $session)
        ->assertHasNoErrors();

    expect(WorkSession::query()->count())->toBe(0);
});

it('books an entry to a work area with a note', function () {
    $area = WorkArea::factory()->create(['name' => 'Licensing']);

    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-08-25')
        ->set('start_time', '09:00')
        ->set('end_time', '12:00')
        ->set('work_area_id', $area->id)
        ->set('note', 'Cleared the renewals backlog')
        ->call('saveSession')
        ->assertHasNoErrors();

    $session = WorkSession::query()->sole();

    expect($session->work_area_id)->toBe($area->id)
        ->and($session->note)->toBe('Cleared the renewals backlog');
});

it('reads back the area and note when editing', function () {
    $area = WorkArea::factory()->create();
    $session = WorkSession::factory()
        ->on('2026-08-25', '09:00', '12:00')
        ->forArea($area)
        ->withNote('Drafted the September report')
        ->create();

    Livewire::test('pages::timesheet')
        ->call('editSession', $session)
        ->assertSet('work_area_id', $area->id)
        ->assertSet('note', 'Drafted the September report');
});

it('clears the area and note back off an entry', function () {
    $area = WorkArea::factory()->create();
    $session = WorkSession::factory()
        ->on('2026-08-25', '09:00', '12:00')
        ->forArea($area)
        ->withNote('Something I would rather not keep')
        ->create();

    Livewire::test('pages::timesheet')
        ->call('editSession', $session)
        ->set('work_area_id', null)
        ->set('note', '')
        ->call('saveSession')
        ->assertHasNoErrors();

    $session->refresh();

    expect($session->work_area_id)->toBeNull()
        ->and($session->note)->toBeNull();
})->note('An empty note is stored as null, not an empty string, so nothing renders for it.');

it('shows the area and note in the day view', function () {
    $area = WorkArea::factory()->create(['name' => 'Licensing']);
    WorkSession::factory()
        ->on('2026-08-27', '09:00', '12:00')
        ->forArea($area)
        ->withNote('Cleared the renewals backlog')
        ->create();

    Livewire::test('pages::timesheet')
        ->set('mode', 'day')
        ->set('focus', '2026-08-27')
        ->assertSee('Licensing')
        ->assertSee('Cleared the renewals backlog');
});

it('rejects a note longer than the field allows', function () {
    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-08-25')
        ->set('start_time', '09:00')
        ->set('end_time', '12:00')
        ->set('note', str_repeat('a', WorkSession::NoteLimit + 1))
        ->call('saveSession')
        ->assertHasErrors('note');

    expect(WorkSession::query()->count())->toBe(0);
});

it('rejects an area that no longer exists', function () {
    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-08-25')
        ->set('start_time', '09:00')
        ->set('end_time', '12:00')
        ->set('work_area_id', 999)
        ->call('saveSession')
        ->assertHasErrors('work_area_id');
});

it('rejects an end time before the start time', function () {
    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-08-25')
        ->set('start_time', '17:00')
        ->set('end_time', '09:00')
        ->call('saveSession')
        ->assertHasErrors('end_time');

    expect(WorkSession::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Balance and performance
|--------------------------------------------------------------------------
*/

it('shows the running balance for the period', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '16:30')->create();
    WorkSession::factory()->on('2026-08-25', '09:00', '17:30')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('Balance')
        ->assertSee('hours owed');
});

it('reads the gauge against the limit in both directions', function () {
    WorkPattern::query()->delete();

    Livewire::test('pages::timesheet')
        ->assertSee('&minus;16h', escape: false)
        ->assertSee('+16h')
        ->assertSee('Zero')
        ->assertSee('in balance');
})->note('With no pattern and no sessions the balance sits at zero.');

it('warns when the balance passes the limit', function () {
    WorkPattern::query()->delete();
    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);

    Livewire::test('pages::timesheet')
        ->assertSee('Over the 16h limit by')
        ->assertSee('+20h')
        ->assertSee('hours banked');
});

it('shows the same balance whichever view is open', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();

    foreach (['day', 'week', 'period'] as $mode) {
        Livewire::test('pages::timesheet')
            ->call('showMode', $mode)
            ->assertSee('hours owed');
    }
})->note('Every view is a slice of the same period.');

it('loads the whole period without a query per day', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();

    DB::enableQueryLog();
    Livewire::test('pages::timesheet');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);
})->note('42 days once cost a query each; they are now batched.');

it('requires signing in', function () {
    auth()->logout();

    $this->get(route('timesheet'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| How a day reads at a glance
|--------------------------------------------------------------------------
*/

it('grounds a bank holiday in its own colour', function () {
    BankHoliday::create(['date' => '2026-08-25', 'title' => 'Summer bank holiday']);

    Livewire::test('pages::timesheet')
        ->call('showWeek', '2026-08-24')
        ->assertSee('Summer bank holiday')
        ->assertSeeHtml('bg-pot-other/[0.08]');
});

it('grounds a day of annual leave in the annual colour', function () {
    LeaveDay::factory()->on('2026-08-25', 7.5)->create();

    Livewire::test('pages::timesheet')
        ->call('showWeek', '2026-08-24')
        ->assertSeeHtml('bg-pot-annual/[0.08]');
});

it('grounds a day banked in lieu in the lieu colour', function () {
    WorkSession::factory()->on('2026-08-25', '18:00', '20:00')->inLieu()->create();

    Livewire::test('pages::timesheet')
        ->call('showWeek', '2026-08-24')
        ->assertSeeHtml('bg-pot-lieu/[0.08]');
});

it('lets a bank holiday outrank leave booked on the same day', function () {
    BankHoliday::create(['date' => '2026-08-25', 'title' => 'Summer bank holiday']);
    LeaveDay::factory()->on('2026-08-25', 7.5)->create();

    Livewire::test('pages::timesheet')
        ->call('showWeek', '2026-08-24')
        ->assertSeeHtml('bg-pot-other/[0.08]')
        ->assertDontSeeHtml('bg-pot-annual/[0.08]');
});
