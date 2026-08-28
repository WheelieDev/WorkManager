<?php

use App\Models\BankHoliday;
use App\Models\LeaveDay;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-09-07 10:00');

    partTimePattern();
});

it('opens on the current leave year', function () {
    Livewire::test('pages::leave')
        ->assertSet('year', 2026)
        ->assertSee('1 Apr 2026 - 31 Mar 2027');
});

it('steps between leave years', function () {
    Livewire::test('pages::leave')
        ->call('shiftYear', 1)
        ->assertSee('1 Apr 2027 - 31 Mar 2028')
        ->call('thisYear')
        ->assertSee('1 Apr 2026 - 31 Mar 2027');
});

it('says how each entitlement was arrived at', function () {
    Livewire::test('pages::leave')
        ->assertSee('27 days at your hours and weeks')
        ->assertSee('8 days at your hours')
        ->assertSee('47.8');
})->note('The two are pro-rata\'d differently, so the page has to say so.');

it('shows one balance with the working out under it', function () {
    Livewire::test('pages::leave')
        ->assertSee('Bookable leave')
        ->assertSee('79.21')   // 27 days for 47.8 paid weeks
        ->assertSee('25.60')   // 8 public holiday days in full
        ->assertSee('less taken by bank holidays')
        ->assertSee('Entitlement');
});

it('shows the unspent allowance as a line in the breakdown', function () {
    BankHoliday::factory()->on('2026-12-28')->create();

    Livewire::test('pages::leave')
        ->assertSee('Public holidays left over')
        ->assertSee('21.60');
})->note('One balance, not two to reconcile by eye.');

it('does not offer public holidays as a balance of their own', function () {
    Livewire::test('pages::leave')
        ->assertDontSee('hours of 25.60 left over')
        ->assertDontSee('Goes to bookable leave');
});

it('no longer lists the bank holidays', function () {
    BankHoliday::factory()->on('2026-12-28', 'Boxing Day')->create();
    BankHoliday::factory()->on('2026-12-25', 'Christmas Day')->create();

    Livewire::test('pages::leave')
        ->assertDontSee('Boxing Day')
        ->assertDontSee('Christmas Day')
        ->assertSee('less taken by bank holidays');
})->note('The breakdown carries the number; the dates are not needed.');

it('names the leave spent supplementing bank holidays', function () {
    foreach (['2026-04-06', '2026-05-04', '2026-05-25', '2026-12-28', '2027-01-04', '2027-02-01', '2027-03-01'] as $date) {
        BankHoliday::factory()->on($date)->create();
    }

    Livewire::test('pages::leave')
        ->assertSee('Public holidays over the allowance')
        ->assertSee('2.40')
        ->assertSee('making up the difference');
})->note('The whole point of keeping the two pots apart.');

it('books a day from the date alone', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-08')
        ->assertSet('showingForm', true)
        ->assertSet('from', '2026-09-08')
        ->assertSet('to', '2026-09-08')
        ->call('saveLeave')
        ->assertHasNoErrors()
        ->assertSet('showingForm', false);

    expect(LeaveDay::query()->sole()->hours)->toBe(4.0);
})->note('No hours to type: the pattern says the day is worth four.');

it('books a range and works out each day from the pattern', function () {
    // Monday 7 to Friday 11 September. The pattern works Monday to Thursday.
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-11')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->count())->toBe(4)
        ->and((float) LeaveDay::query()->sum('hours'))->toBe(16.0)
        ->and(LeaveDay::query()->orderByDesc('date')->first()->date->toDateString())->toBe('2026-09-10');
})->note('Friday is not worked, so it is not booked.');

it('skips bank holidays inside a range', function () {
    BankHoliday::factory()->on('2026-12-28', 'Boxing Day')->create();

    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-12-28')
        ->set('to', '2026-12-31')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->pluck('date')->map->toDateString()->all())
        ->toBe(['2026-12-29', '2026-12-30', '2026-12-31']);
})->note('A bank holiday is already paid for; booking leave on it would waste it.');

it('skips a month the pattern does not work', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-08-24')
        ->set('to', '2026-08-28')
        ->call('saveLeave')
        ->assertHasErrors('from');

    expect(LeaveDay::query()->count())->toBe(0);
});

it('previews the days before they are booked', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-11')
        ->assertSee('4 days')
        ->assertSee('16.00h')
        ->assertSee('Mon 7 Sep')
        ->assertDontSee('Fri 11 Sep');
});

it('books part of a single day', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-08')
        ->set('hours', '2')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->sole()->hours)->toBe(2.0);
});

it('never books more hours than the day is worth', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-08')
        ->set('hours', '9')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->sole()->hours)->toBe(4.0);
});

it('carries the end of the range along with the start', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('from', '2026-09-14')
        ->assertSet('to', '2026-09-14');
});

it('refuses a range that ends before it starts', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-14')
        ->set('to', '2026-09-07')
        ->call('saveLeave')
        ->assertHasErrors('to');
});

it('changes a booking', function () {
    $leave = LeaveDay::factory()->on('2026-09-08', 4.0)->create();

    Livewire::test('pages::leave')
        ->call('editLeave', $leave)
        ->assertSet('from', '2026-09-08')
        ->assertSet('hours', '4')
        ->set('hours', '2')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect($leave->fresh()->hours)->toBe(2.0)
        ->and(LeaveDay::query()->count())->toBe(1);
});

it('cancels a booking', function () {
    $leave = LeaveDay::factory()->on('2026-09-08', 4.0)->create();

    Livewire::test('pages::leave')
        ->call('deleteLeave', $leave)
        ->assertHasNoErrors();

    expect(LeaveDay::query()->count())->toBe(0);
});

it('does not book the same day twice', function () {
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-08')->call('saveLeave');
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-08')->set('hours', '2')->call('saveLeave');

    expect(LeaveDay::query()->count())->toBe(1)
        ->and(LeaveDay::query()->sole()->hours)->toBe(2.0);
});

it('rejects a booking of nothing', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-08')
        ->set('hours', '0')
        ->call('saveLeave')
        ->assertHasErrors('hours');

    expect(LeaveDay::query()->count())->toBe(0);
});

it('counts booked leave against what is left', function () {
    LeaveDay::factory()->on('2026-09-08', 4.0)->create();

    // 79.21 base + 25.60 unspent allowance - 4.00 booked
    Livewire::test('pages::leave')->assertSee('100.81');
});

it('names the leave on the timesheet day', function () {
    LeaveDay::factory()->on('2026-09-08', 2.0)->create();

    Livewire::test('pages::timesheet')
        ->call('showDay', '2026-09-08')
        ->assertSee('Annual leave 2.00h')
        ->assertSee('0.00 / 2.00');
})->note('Otherwise a day quietly expecting less makes no sense.');

it('names the leave on the timesheet week', function () {
    LeaveDay::factory()->on('2026-09-08', 2.0)->create();

    Livewire::test('pages::timesheet')
        ->assertSee('Leave 2.00h');
});

it('requires signing in', function () {
    auth()->logout();

    $this->get(route('leave'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| The booked leave table
|--------------------------------------------------------------------------
*/

it('tabulates the booked leave', function () {
    LeaveDay::factory()->on('2026-09-07', 4.0)->create();
    LeaveDay::factory()->on('2026-09-08', 2.0)->create();

    Livewire::test('pages::leave')
        ->assertSee('Booked leave')
        ->assertSeeInOrder(['Date', 'Taken', 'Hours'])
        ->assertSeeInOrder(['Mon 7 Sep 2026', 'Full day', 'Tue 8 Sep 2026', 'Part day'])
        ->assertSee('2 days')
        ->assertSee('Total');
});

it('totals the table by what the leave actually costs', function () {
    LeaveDay::factory()->on('2026-09-07', 4.0)->create();
    LeaveDay::factory()->on('2026-09-08', 2.0)->create();

    Livewire::test('pages::leave')->assertSee('6.00');
});

it('marks a booking that costs nothing in the table', function () {
    BankHoliday::factory()->on('2026-12-28')->create();
    LeaveDay::factory()->on('2026-12-28', 4.0)->create();

    Livewire::test('pages::leave')
        ->assertSee('already a bank holiday')
        ->assertSee('0.00');
});

it('invites a first booking when the table is empty', function () {
    Livewire::test('pages::leave')
        ->assertSee('No leave booked this year')
        ->assertSee('the hours come from your pattern');
});
