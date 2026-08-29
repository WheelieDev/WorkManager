<?php

use App\Enums\LeaveType;
use App\Enums\WorkKind;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;
use App\Support\LieuLedger;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    // The balance mechanics are what is under test here, so tracking runs from
    // the beginning; the start date has its own tests.
    config(['flexi.tracking_starts' => null]);
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-09-07 10:00');

    // Monday to Thursday, 09:00 to 13:00.
    partTimePattern();
});

/*
|--------------------------------------------------------------------------
| Tracking
|--------------------------------------------------------------------------
*/

it('starts the timer as time off in lieu', function () {
    Livewire::test('pages::dashboard')
        ->set('kind', WorkKind::Lieu->value)
        ->call('startTimer')
        ->assertSee('Time off in lieu');

    expect(WorkSession::current()->kind)->toBe(WorkKind::Lieu);
});

it('starts the timer as contracted hours unless told otherwise', function () {
    Livewire::test('pages::dashboard')->call('startTimer');

    expect(WorkSession::current()->kind)->toBe(WorkKind::Contracted);
});

it('keeps time worked in lieu out of the flexi balance', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '17:00')->inLieu()->create();

    $calculator = new FlexiCalculator;
    $date = Carbon::parse('2026-09-07');

    expect($calculator->workedHours($date))->toBe(0.0)
        ->and($calculator->lieuHours($date))->toBe(4.0)
        // The pattern still wanted its 4 hours, and none of them were worked.
        ->and($calculator->deltaFor($date))->toBe(-4.0);
});

it('records the kind against an entry added by hand', function () {
    Livewire::test('pages::timesheet')
        ->call('addSession', '2026-09-07')
        ->set('start_time', '18:00')
        ->set('end_time', '20:00')
        ->set('kind', WorkKind::Lieu->value)
        ->call('saveSession')
        ->assertHasNoErrors();

    expect(WorkSession::query()->sole()->kind)->toBe(WorkKind::Lieu);
});

/*
|--------------------------------------------------------------------------
| The pot
|--------------------------------------------------------------------------
*/

it('banks completed lieu sessions and spends what is booked off', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '19:00')->inLieu()->create();
    WorkSession::factory()->on('2026-09-08', '09:00', '13:00')->create();
    WorkSession::factory()->on('2026-09-09', '13:00', '17:00')->inLieu()->running()->create();

    $lieu = new LieuLedger;

    expect($lieu->earned())->toBe(6.0)
        ->and($lieu->booked())->toBe(0.0)
        ->and($lieu->balance())->toBe(6.0);

    LeaveDay::factory()->create(['date' => '2026-09-15', 'type' => LeaveType::Lieu, 'hours' => 4.0]);

    expect((new LieuLedger)->balance())->toBe(2.0);
})->note('Only completed sessions count, and only the ones tracked as lieu.');

it('adds the opening balance to what has been worked', function () {
    Setting::write('lieu_opening_hours', '7.5');
    WorkSession::factory()->on('2026-09-07', '13:00', '15:00')->inLieu()->create();

    expect((new LieuLedger)->balance())->toBe(9.5);
});

it('does not let lieu leave come off the annual pot', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '17:00')->inLieu()->create();

    $before = (new LeaveLedger(LeaveYear::current()))->annualRemaining();

    LeaveDay::factory()->create(['date' => '2026-09-15', 'type' => LeaveType::Lieu, 'hours' => 4.0]);

    expect((new LeaveLedger(LeaveYear::current()))->annualRemaining())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Booking
|--------------------------------------------------------------------------
*/

it('books lieu against what has been banked', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '17:00')->inLieu()->create();

    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-15')
        ->set('type', LeaveType::Lieu->value)
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect((new LieuLedger)->balance())->toBe(0.0)
        ->and(LeaveDay::query()->sole()->type)->toBe(LeaveType::Lieu);
});

it('refuses to book more lieu than has been banked', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '15:00')->inLieu()->create();

    Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-15')
        ->set('type', LeaveType::Lieu->value)
        ->call('saveLeave')
        ->assertHasErrors('from')
        ->assertSee('you have only 2.00h banked');

    expect(LeaveDay::query()->count())->toBe(0);
})->note('The pot is earned before it is spent, so it must never go negative.');

it('credits back the lieu a booking gives up when it is moved', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '17:00')->inLieu()->create();

    $component = Livewire::test('pages::leave')
        ->call('bookLeave', '2026-09-15')
        ->set('type', LeaveType::Lieu->value)
        ->call('saveLeave')
        ->assertHasNoErrors();

    $request = LeaveRequest::query()->sole();

    // The whole balance is spent, so moving the day is only affordable because
    // the day it is moving off is given back first.
    $component->call('editLeave', $request->id)
        ->set('from', '2026-09-16')
        ->set('to', '2026-09-16')
        ->call('saveLeave')
        ->assertHasNoErrors();

    expect((new LieuLedger)->balance())->toBe(0.0)
        ->and(LeaveDay::query()->sole()->date->toDateString())->toBe('2026-09-16');
});

/*
|--------------------------------------------------------------------------
| Opening balance
|--------------------------------------------------------------------------
*/

it('saves an opening balance from the leave page', function () {
    Livewire::test('pages::leave')
        ->set('tab', 'lieu')
        ->call('editOpening')
        ->set('opening', '5')
        ->call('saveOpening')
        ->assertHasNoErrors()
        ->assertSet('showingOpening', false)
        ->assertSee('5.00');

    expect((new LieuLedger)->balance())->toBe(5.0);
});

it('refuses an opening balance that would overdraw the pot', function () {
    Setting::write('lieu_opening_hours', '4');
    LeaveDay::factory()->create(['date' => '2026-09-15', 'type' => LeaveType::Lieu, 'hours' => 4.0]);

    Livewire::test('pages::leave')
        ->set('tab', 'lieu')
        ->call('editOpening')
        ->set('opening', '1')
        ->call('saveOpening')
        ->assertHasErrors('opening');

    expect((new LieuLedger)->openingBalance())->toBe(4.0);
});

it('refuses a negative opening balance', function () {
    Livewire::test('pages::leave')
        ->call('editOpening')
        ->set('opening', '-3')
        ->call('saveOpening')
        ->assertHasErrors('opening');
});

it('says on the dashboard what today banked in lieu', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '16:00')->inLieu()->create();

    Livewire::test('pages::dashboard')
        ->assertSee('Banked in lieu')
        ->assertSee('3.00h');
})->note('The hours are worked but move none of today\'s figures, so the widget says so.');

it('shows the pot on the leave and dashboard pages', function () {
    WorkSession::factory()->on('2026-09-07', '13:00', '17:00')->inLieu()->create();

    Livewire::test('pages::leave')
        ->set('tab', 'lieu')
        ->assertSee('Time off in lieu')
        ->assertSee('4.00')
        ->assertSee('Banked in lieu');

    Livewire::test('pages::dashboard')
        ->assertSee('Time off in lieu')
        ->assertSee('4.00h');
});
