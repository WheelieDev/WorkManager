<?php

use App\Enums\Weekday;
use App\Models\BankHoliday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['flexi.tracking_starts' => null]);
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

it('returns 401 without a token', function () {
    $this->getJson('/api/hours')->assertUnauthorized();
});

it('reports today against the pattern and where the period stands', function () {
    Sanctum::actingAs(User::factory()->create());
    WorkSession::factory()->on('2026-08-27', '09:00', '17:30')->create();

    $this->getJson('/api/hours')
        ->assertOk()
        ->assertJsonPath('data.date', '2026-08-27')
        ->assertJsonPath('data.today.worked', 8.5)
        ->assertJsonPath('data.today.expected', 7.5)
        ->assertJsonPath('data.today.difference', 1)
        ->assertJsonPath('data.today.lieu', 0)
        ->assertJsonPath('data.today.leave', 0)
        ->assertJsonPath('data.today.bank_holiday', null)
        ->assertJsonPath('data.period.label', '10 Aug - 20 Sep 2026')
        ->assertJsonPath('data.period.start', '2026-08-10')
        ->assertJsonPath('data.period.end', '2026-09-20')
        ->assertJsonPath('data.period.days_left', 24)
        ->assertJsonPath('data.period.limit', 16);
});

it('leaves a running session out of the hours worked', function () {
    Sanctum::actingAs(User::factory()->create());
    WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

    $this->getJson('/api/hours')
        ->assertOk()
        ->assertJsonPath('data.today.worked', 0)
        ->assertJsonPath('data.today.difference', -7.5);
})->note('As on the dashboard: the timer moves the figures when it is stopped.');

it('keeps lieu hours beside the figures rather than in them', function () {
    Sanctum::actingAs(User::factory()->create());
    WorkSession::factory()->on('2026-08-27', '09:00', '16:30')->create();
    WorkSession::factory()->inLieu()->on('2026-08-27', '18:00', '20:00')->create();

    $this->getJson('/api/hours')
        ->assertOk()
        ->assertJsonPath('data.today.worked', 7.5)
        ->assertJsonPath('data.today.lieu', 2);
});

it('names a bank holiday and expects nothing on it', function () {
    Sanctum::actingAs(User::factory()->create());
    BankHoliday::factory()->create(['date' => '2026-08-27', 'title' => 'Summer bank holiday']);

    $this->getJson('/api/hours')
        ->assertOk()
        ->assertJsonPath('data.today.expected', 0)
        ->assertJsonPath('data.today.bank_holiday', 'Summer bank holiday');
});

it('flags a balance past the limit', function () {
    Sanctum::actingAs(User::factory()->create());
    WorkPattern::query()->delete();
    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);

    $this->getJson('/api/hours')
        ->assertOk()
        ->assertJsonPath('data.period.balance', 20)
        ->assertJsonPath('data.period.over_limit', true);
});
