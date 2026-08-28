<?php

use App\Enums\Weekday;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;

beforeEach(function () {
    // These cover the balance mechanics themselves, so they run with no
    // tracking start; the start date has its own tests.
    config(['flexi.tracking_starts' => null]);
});

/**
 * Give every weekday in every period a 7.5 hour expectation.
 */
function sevenAndAHalfPattern(string $startDate = '2026-01-01'): WorkPattern
{
    $pattern = WorkPattern::factory()->create(['start_date' => $startDate]);

    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '16:30',
        ]);
    }

    return $pattern;
}

it('expects the pattern hours on a weekday', function () {
    sevenAndAHalfPattern();

    expect((new FlexiCalculator)->expectedHours(now()->parse('2026-08-10')))->toBe(7.5);
});

it('expects nothing at the weekend', function () {
    sevenAndAHalfPattern();

    $calculator = new FlexiCalculator;

    expect($calculator->expectedHours(now()->parse('2026-08-15')))->toBe(0.0)
        ->and($calculator->expectedHours(now()->parse('2026-08-16')))->toBe(0.0);
});

it('expects nothing when no pattern is in effect', function () {
    sevenAndAHalfPattern('2026-09-01');

    expect((new FlexiCalculator)->expectedHours(now()->parse('2026-08-10')))->toBe(0.0);
});

it('expects nothing on a weekday the pattern does not work', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    $pattern->days()->create(['weekday' => Weekday::Monday, 'start_time' => null, 'end_time' => null]);

    expect((new FlexiCalculator)->expectedHours(now()->parse('2026-08-10')))->toBe(0.0);
});

it('applies a newer pattern only from its start date', function () {
    sevenAndAHalfPattern('2026-01-01');

    $newer = WorkPattern::factory()->create(['start_date' => '2026-08-17']);
    foreach (Weekday::cases() as $weekday) {
        $newer->days()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '13:00']);
    }

    $calculator = new FlexiCalculator;

    expect($calculator->expectedHours(now()->parse('2026-08-14')))->toBe(7.5)
        ->and($calculator->expectedHours(now()->parse('2026-08-17')))->toBe(4.0);
});

it('counts completed sessions towards hours worked', function () {
    WorkSession::factory()->on('2026-08-10', '09:00', '17:00')->create();
    WorkSession::factory()->on('2026-08-10', '18:00', '19:30')->create();

    expect((new FlexiCalculator)->workedHours(now()->parse('2026-08-10')))->toBe(9.5);
});

it('does not count a running session towards hours worked', function () {
    WorkSession::factory()->running()->create(['started_at' => '2026-08-10 09:00']);

    expect((new FlexiCalculator)->workedHours(now()->parse('2026-08-10')))->toBe(0.0);
});

it('attributes a session crossing midnight to the day it started', function () {
    WorkSession::factory()->create([
        'started_at' => '2026-08-10 22:00',
        'ended_at' => '2026-08-11 02:00',
    ]);

    $calculator = new FlexiCalculator;

    expect($calculator->workedHours(now()->parse('2026-08-10')))->toBe(4.0)
        ->and($calculator->workedHours(now()->parse('2026-08-11')))->toBe(0.0);
});

it('reports a positive delta when overworked', function () {
    sevenAndAHalfPattern();
    WorkSession::factory()->on('2026-08-10', '09:00', '18:00')->create();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-10')))->toBe(1.5);
});

it('reports a negative delta when underworked', function () {
    sevenAndAHalfPattern();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-10')))->toBe(-7.5);
});

it('ignores days that have not happened yet', function () {
    sevenAndAHalfPattern();
    $this->travelTo('2026-08-11');

    WorkSession::factory()->on('2026-08-10', '09:00', '16:30')->create();
    WorkSession::factory()->on('2026-08-11', '09:00', '16:30')->create();

    expect((new FlexiCalculator)->accruedIn(new FlexiPeriod(0)))->toBe(0.0);
})->note('Without this, an open period would open ~250 hours in deficit.');

it('carries nothing into the anchor period', function () {
    sevenAndAHalfPattern();
    $this->travelTo('2026-08-27');

    expect((new FlexiCalculator)->carryInto(new FlexiPeriod(0)))->toBe(0.0);
});

it('caps a positive carry at the limit and loses the excess', function () {
    $this->travelTo('2026-09-25');

    // Every weekday off, so expected is zero; one long session creates +20.
    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);

    $calculator = new FlexiCalculator;

    expect($calculator->accruedIn(new FlexiPeriod(0)))->toBe(20.0)
        ->and($calculator->carryInto(new FlexiPeriod(1)))->toBe(16.0);
});

it('carries a negative balance forward in full', function () {
    sevenAndAHalfPattern();
    $this->travelTo('2026-09-25');

    // Nothing worked in the closed period at all.
    $calculator = new FlexiCalculator;

    expect($calculator->accruedIn(new FlexiPeriod(0)))->toBeLessThan(-16.0)
        ->and($calculator->carryInto(new FlexiPeriod(1)))
        ->toBe($calculator->accruedIn(new FlexiPeriod(0)));
})->note('Underworked hours are never written off.');

it('does not carry from a period that is still open', function () {
    $this->travelTo('2026-08-27');

    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);

    expect((new FlexiCalculator)->carryInto(new FlexiPeriod(1)))->toBe(0.0);
});

it('adds the carry to what has accrued in the period', function () {
    $this->travelTo('2026-09-25');

    WorkSession::factory()->create(['started_at' => '2026-08-15 00:00', 'ended_at' => '2026-08-15 20:00']);
    WorkSession::factory()->on('2026-09-21', '09:00', '14:00')->create();

    expect((new FlexiCalculator)->balanceFor(new FlexiPeriod(1)))->toBe(21.0);
});

it('flags a balance beyond the limit in either direction', function () {
    $calculator = new FlexiCalculator;

    expect($calculator->isOverLimit(16.0))->toBeFalse()
        ->and($calculator->isOverLimit(16.5))->toBeTrue()
        ->and($calculator->isOverLimit(-16.5))->toBeTrue();
});
