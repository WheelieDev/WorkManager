<?php

use App\Enums\LeaveType;
use App\Models\BankHoliday;
use App\Models\LeaveCarryover;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    // These cover the calculation, so they run with no awarded figure;
    // the awarded path has its own tests.
    config(['leave.annual_leave_hours' => null]);
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

it('changes a request', function () {
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-08')->call('saveLeave');

    $request = LeaveRequest::query()->sole();

    Livewire::test('pages::leave')
        ->call('editLeave', $request)
        ->assertSet('from', '2026-09-08')
        ->assertSet('hours', '4')
        ->set('hours', '2')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->sole()->hours)->toBe(2.0)
        ->and(LeaveRequest::query()->count())->toBe(1);
});

it('cancels a request and every day it booked', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-10')
        ->call('saveLeave');

    expect(LeaveDay::query()->count())->toBe(4);

    Livewire::test('pages::leave')
        ->call('deleteLeave', LeaveRequest::query()->sole())
        ->assertHasNoErrors();

    expect(LeaveDay::query()->count())->toBe(0)
        ->and(LeaveRequest::query()->count())->toBe(0);
});

it('moves a day to the newer request rather than booking it twice', function () {
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-08')->call('saveLeave');
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-08')->set('hours', '2')->call('saveLeave');

    expect(LeaveDay::query()->count())->toBe(1)
        ->and(LeaveDay::query()->sole()->hours)->toBe(2.0)
        ->and(LeaveRequest::query()->count())->toBe(1);
})->note('The emptied request is cleared away with its last day.');

it('keeps the days a newer request did not take', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-10')
        ->call('saveLeave');

    Livewire::test('pages::leave')->call('bookLeave', '2026-09-08')->set('hours', '2')->call('saveLeave');

    expect(LeaveDay::query()->count())->toBe(4)
        ->and(LeaveRequest::query()->count())->toBe(2);
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

it('tabulates leave one row per request', function () {
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-07')->set('to', '2026-09-10')->call('saveLeave');
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-21')->call('saveLeave');

    Livewire::test('pages::leave')
        ->assertSee('Booked leave')
        ->assertSeeInOrder(['Dates', 'Days', 'Bank holidays', 'Hours'])
        ->assertSee('Mon 7 Sep to Thu 10 Sep 2026')
        ->assertSee('Mon 21 Sep 2026')
        ->assertSee('2 requests')
        ->assertSee('Total');
})->note('A week off is one request, not four rows.');

it('counts the bank holidays inside a request', function () {
    BankHoliday::factory()->on('2026-12-28', 'Boxing Day')->create();

    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-12-28')
        ->set('to', '2026-12-31')
        ->call('saveLeave');

    // Three days booked, and the bank holiday inside them cost nothing.
    Livewire::test('pages::leave')
        ->assertSee('Mon 28 Dec to Thu 31 Dec 2026')
        ->assertSee('12.00');
});

it('totals the requests by days, holidays and hours', function () {
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-07')->set('to', '2026-09-10')->call('saveLeave');

    Livewire::test('pages::leave')
        ->assertSee('16.00')
        ->assertSee('Total');
});

it('invites a first booking when the table is empty', function () {
    Livewire::test('pages::leave')
        ->assertSee('No leave booked this year')
        ->assertSee('the hours come from your pattern');
});

it('offers edit and cancel on every request', function () {
    Livewire::test('pages::leave')->call('bookLeave', '2026-09-07')->set('to', '2026-09-10')->call('saveLeave');

    $request = LeaveRequest::query()->sole();

    Livewire::test('pages::leave')
        ->assertSee('Edit leave from 7 September 2026')
        ->assertSee('Cancel leave from 7 September 2026')
        ->assertSee('The 4 days it booked will be given back.');
})->note('Both actions were hidden behind a hover or a click on the date.');

/*
|--------------------------------------------------------------------------
| Carried in
|--------------------------------------------------------------------------
*/

it('shows what is carried in and where it came from', function () {
    Livewire::test('pages::leave')
        ->assertSee('Carried in from 2025/26')
        ->assertSee('calculated');
});

it('says when the carried figure was entered by hand', function () {
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 12.5]);

    Livewire::test('pages::leave')
        ->assertSee('entered')
        ->assertSee('12.50');
});

it('enters a carried figure', function () {
    Livewire::test('pages::leave')
        ->call('editCarryover')
        ->assertSet('showingCarryover', true)
        ->set('carryover', '9.5')
        ->call('saveCarryover')
        ->assertHasNoErrors()
        ->assertSet('showingCarryover', false);

    expect(LeaveCarryover::query()->sole()->hours)->toBe(9.5);
});

it('adds the carried figure to the entitlement on the page', function () {
    Livewire::test('pages::leave')
        ->call('editCarryover')
        ->set('carryover', '10')
        ->call('saveCarryover');

    // 79.21 base + 25.60 public holidays + 10.00 carried in
    Livewire::test('pages::leave')->assertSee('114.81');
});

it('clears the carried figure back to the calculation', function () {
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 12.5]);

    Livewire::test('pages::leave')
        ->call('editCarryover')
        ->assertSet('carryover', '12.5')
        ->set('carryover', '')
        ->call('saveCarryover')
        ->assertHasNoErrors();

    expect(LeaveCarryover::query()->count())->toBe(0);

    Livewire::test('pages::leave')->assertSee('calculated');
});

it('keeps the carried figure to its own leave year', function () {
    Livewire::test('pages::leave')
        ->call('editCarryover')
        ->set('carryover', '9.5')
        ->call('saveCarryover');

    Livewire::test('pages::leave')
        ->call('shiftYear', 1)
        ->call('editCarryover')
        ->assertSet('carryover', '');
});

it('books leave of a chosen type', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-10')
        ->set('type', LeaveType::Hospital->value)
        ->set('hours', '2')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->sole())
        ->type->toBe(LeaveType::Hospital)
        ->hours->toBe(2.0);
});

it('defaults a new booking back to annual leave', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-10')
        ->set('type', LeaveType::Unpaid->value)
        ->call('bookLeave', '2026-09-11')
        ->assertSet('type', LeaveType::Annual->value);
});

it('keeps other absence on a day when leave is booked over it', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-10')
        ->set('type', LeaveType::Hospital->value)
        ->set('hours', '2')
        ->call('saveLeave')
        ->assertHasNoErrors();

    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-10')
        ->set('type', LeaveType::Annual->value)
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->count())->toBe(2)
        ->and(LeaveDay::query()->where('type', LeaveType::Hospital)->exists())->toBeTrue()
        ->and(LeaveDay::query()->where('type', LeaveType::Annual)->exists())->toBeTrue();
})->note('Rebooking must displace only its own type, or an appointment vanishes silently.');

it('replaces leave of the same type when it is rebooked', function () {
    foreach ([2, 3] as $hours) {
        Livewire::test('pages::leave')
            ->call('bookLeave', '2026-09-10')
            ->set('type', LeaveType::Hospital->value)
            ->set('hours', (string) $hours)
            ->call('saveLeave')
            ->assertHasNoErrors();
    }

    expect(LeaveDay::query()->sole()->hours)->toBe(3.0);
});

it('shows the other leave allowances', function () {
    Livewire::test('pages::leave')
        ->assertSee('Other leave')
        ->assertSee('Volunteering leave')
        ->assertSee('3.20');
})->note('The volunteering day is 1 day x 7.5h x 0.4267.');

it('rejects a type that does not exist', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-10')
        ->set('type', 'sabbatical')
        ->call('saveLeave')
        ->assertHasErrors('type');
});

it('says what sick pay the length of service earns', function () {
    config(['leave.service_started' => '2020-01-01']);

    Livewire::test('pages::leave')
        ->assertSee('Sickness allowance')
        ->assertSee('Full pay')
        ->assertSee('Half pay')
        ->assertSee('6 months full pay and 6 months half pay');
});

it('asks for a service start before guessing at sick pay', function () {
    config(['leave.service_started' => null]);

    Livewire::test('pages::leave')
        ->assertSee('Sickness allowance')
        ->assertSee('service_started')
        ->assertDontSee('Full pay');
})->note('A wrong sick pay figure is worse than none while someone is off sick.');

it('books sickness against the sickness allowance', function () {
    config(['leave.service_started' => '2020-01-01']);

    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-10')
        ->set('type', LeaveType::Sickness->value)
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->sole()->type)->toBe(LeaveType::Sickness);

    // Sickness has its own accounting, so it is not listed with the rest.
    Livewire::test('pages::leave')->assertDontSee('Sickness</dt>');
});

it('offers both ends of the range when changing a request', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-10')
        ->call('saveLeave');

    $request = LeaveRequest::query()->sole();

    Livewire::test('pages::leave')
        ->call('editLeave', $request)
        ->assertSet('from', '2026-09-07')
        ->assertSet('to', '2026-09-10')
        ->assertSeeHtml('wire:model.live="to"');
})->note('Without the second box a multi-day request could not be shortened or extended.');

it('extends a request when the range is changed', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-08')
        ->call('saveLeave');

    $request = LeaveRequest::query()->sole();
    $before = LeaveDay::query()->count();

    Livewire::test('pages::leave')
        ->call('editLeave', $request)
        ->set('to', '2026-09-10')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->count())->toBeGreaterThan($before)
        ->and(LeaveRequest::query()->sole()->ends_on->toDateString())->toBe('2026-09-10');
});

it('shortens a request and gives the days back', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-07')
        ->set('to', '2026-09-10')
        ->call('saveLeave');

    $request = LeaveRequest::query()->sole();

    Livewire::test('pages::leave')
        ->call('editLeave', $request)
        ->set('to', '2026-09-08')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveRequest::query()->sole()->ends_on->toDateString())->toBe('2026-09-08')
        ->and(LeaveDay::query()->where('date', '>', '2026-09-08')->exists())->toBeFalse();
})->note('Days dropped from the range must not stay booked against the request.');
