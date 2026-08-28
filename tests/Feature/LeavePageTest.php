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

it('shows the two pots apart from each other', function () {
    Livewire::test('pages::leave')
        ->assertSee('Bookable leave')
        ->assertSee('Public holidays')
        ->assertSee('84.50')
        ->assertSee('25.60');
});

it('shows what each bank holiday costs', function () {
    BankHoliday::factory()->on('2026-12-28', 'Boxing Day')->create();
    BankHoliday::factory()->on('2026-12-25', 'Christmas Day')->create();

    Livewire::test('pages::leave')
        ->assertSee('Boxing Day')
        ->assertSee('4.00h')
        ->assertSee('Christmas Day')
        ->assertSee('not a working day');
});

it('names the leave spent supplementing bank holidays', function () {
    foreach (['2026-04-06', '2026-05-04', '2026-05-25', '2026-12-28', '2027-01-04', '2027-02-01', '2027-03-01'] as $date) {
        BankHoliday::factory()->on($date)->create();
    }

    Livewire::test('pages::leave')
        ->assertSee('Spent on bank holidays')
        ->assertSee('2.40')
        ->assertSee('making up the difference');
})->note('The whole point of keeping the two pots apart.');

it('books leave for a day', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-08')
        ->assertSet('showingForm', true)
        ->assertSet('hours', '4')
        ->call('saveLeave')
        ->assertHasNoErrors()
        ->assertSet('showingForm', false);

    expect(LeaveDay::query()->sole()->hours)->toBe(4.0);
})->note('The form offers the hours that day actually works.');

it('books part of a day', function () {
    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-08')
        ->set('hours', '2')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect(LeaveDay::query()->sole()->hours)->toBe(2.0);
});

it('changes a booking', function () {
    $leave = LeaveDay::factory()->on('2026-09-08', 4.0)->create();

    Livewire::test('pages::leave')
        ->call('editLeave', $leave)
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

    Livewire::test('pages::leave')->assertSee('80.50');
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
