<?php

use App\Enums\Weekday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
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

it('starts the timer', function () {
    Livewire::test('pages::dashboard')
        ->assertSee('Not running')
        ->call('startTimer')
        ->assertSee('Started at 10:00');

    expect(WorkSession::current())->not->toBeNull();
});

it('does not start a second timer while one runs', function () {
    Livewire::test('pages::dashboard')
        ->call('startTimer')
        ->call('startTimer');

    expect(WorkSession::query()->count())->toBe(1);
});

it('stops the running timer and records the hours', function () {
    $component = Livewire::test('pages::dashboard')->call('startTimer');

    $this->travelTo('2026-08-27 12:30');

    $component->call('stopTimer')->assertSee('Not running');

    expect(WorkSession::query()->sole()->hours())->toBe(2.5);
});

it('discards a session started and stopped in the same instant', function () {
    $component = Livewire::test('pages::dashboard')->call('startTimer');

    $component->call('stopTimer')->assertSee('Not running');

    expect(WorkSession::query()->count())->toBe(0);
})->note('Clicking start then stop straight away used to throw and 500 the page.');

it('stopping with no timer running does nothing', function () {
    Livewire::test('pages::dashboard')->call('stopTimer');

    expect(WorkSession::query()->count())->toBe(0);
});

it('shows today against the pattern', function () {
    WorkSession::factory()->on('2026-08-27', '09:00', '17:30')->create();

    Livewire::test('pages::dashboard')
        ->assertSee('8.50h')
        ->assertSee('7.50h')
        ->assertSee('+1.00h');
});

it('shows the period balance and the days remaining', function () {
    Livewire::test('pages::dashboard')
        ->assertSee('10 Aug - 20 Sep 2026')
        ->assertSee('24 days left');
});

it('warns when past the positive limit and says what will carry', function () {
    // No pattern, so every hour worked is a surplus.
    WorkPattern::query()->delete();
    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);

    Livewire::test('pages::dashboard')
        ->assertSee('+20.00h')
        ->assertSee('You are 4.00h beyond the 16h limit.')
        ->assertSee('Only 16h will carry into the next period.');
});

it('warns when past the negative limit without mentioning carry', function () {
    // Pattern expects 7.5h a day and nothing has been worked.
    Livewire::test('pages::dashboard')
        ->assertSee('beyond the 16h limit')
        ->assertDontSee('Only 16h will carry into the next period.');
});
