<?php

use App\Enums\Weekday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
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

it('opens on the week containing today', function () {
    Livewire::test('pages::timesheet')
        ->assertSet('week', '2026-08-24')
        ->assertSee('Week 3 of 6');
});

it('steps between weeks', function () {
    Livewire::test('pages::timesheet')
        ->call('shiftWeek', -1)
        ->assertSet('week', '2026-08-17')
        ->assertSee('Week 2 of 6')
        ->call('shiftWeek', 1)
        ->call('shiftWeek', 1)
        ->assertSet('week', '2026-08-31')
        ->call('thisWeek')
        ->assertSet('week', '2026-08-24');
});

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
    // Monday to Wednesday of week 3 worked to pattern, plus an extra hour on Tuesday.
    WorkSession::factory()->on('2026-08-24', '09:00', '16:30')->create();
    WorkSession::factory()->on('2026-08-25', '09:00', '17:30')->create();

    Livewire::test('pages::timesheet')
        ->assertSee('Balance');
});

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
