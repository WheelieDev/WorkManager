<?php

use App\Models\LeaveCarryover;
use App\Models\LeaveDay;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;

beforeEach(function () {
    // These cover the calculation, so they run with no awarded figure;
    // the awarded path has its own tests.
    config(['leave.annual_leave_hours' => null]);
    // Running from 2024 so the earlier leave years have a pattern behind them.
    partTimePattern('2024-01-01');
});

it('carries nothing in when the year before has no leave recorded', function () {
    expect((new LeaveLedger(new LeaveYear(2026)))->carriedIn())->toBe(0.0);
})->note('An untouched year is not evidence that nothing was carried over.');

it('takes a figure entered by hand', function () {
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 12.5]);

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->carriedIn())->toBe(12.5)
        ->and($ledger->carryWasEntered())->toBeTrue();
});

it('adds what is carried in to the entitlement', function () {
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 10.0]);

    $ledger = new LeaveLedger(new LeaveYear(2026));

    // 79.21 base + 25.60 unspent public holidays + 10.00 carried in
    expect($ledger->annualEntitlement())->toBe(114.81)
        ->and($ledger->annualRemaining())->toBe(114.81);
});

it('works the carry out from the year before when that year has leave', function () {
    // A day booked in 2025/26 makes that year countable.
    LeaveDay::factory()->on('2025-09-08', 4.0)->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));
    $previous = new LeaveLedger(new LeaveYear(2025));

    expect($previous->annualBooked())->toBe(4.0)
        ->and($ledger->carriedIn())->toBe($previous->annualRemaining())
        ->and($ledger->carryWasEntered())->toBeFalse();
});

it('prefers a figure entered by hand over the calculation', function () {
    LeaveDay::factory()->on('2025-09-08', 4.0)->create();
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 3.0]);

    expect((new LeaveLedger(new LeaveYear(2026)))->carriedIn())->toBe(3.0);
});

it('follows the chain back through several years', function () {
    LeaveCarryover::factory()->create(['leave_year' => 2024, 'hours' => 5.0]);
    LeaveDay::factory()->on('2024-09-09', 4.0)->create();
    LeaveDay::factory()->on('2025-09-08', 4.0)->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    // 2024 carried 5.00 in by hand, so 2025 and then 2026 both have a year
    // behind them worth counting.
    expect($ledger->carriedIn())->toBeGreaterThan(0.0);
});

it('caps the carry when a cap is set', function () {
    config(['leave.carryover_cap_hours' => 15.0]);
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 40.0]);

    expect((new LeaveLedger(new LeaveYear(2026)))->carriedIn())->toBe(15.0);
})->note('Anything above the cap is lost at the turn of the year.');

it('leaves a carry under the cap alone', function () {
    config(['leave.carryover_cap_hours' => 15.0]);
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => 9.0]);

    expect((new LeaveLedger(new LeaveYear(2026)))->carriedIn())->toBe(9.0);
});

it('carries a negative balance forward in full', function () {
    LeaveCarryover::factory()->create(['leave_year' => 2026, 'hours' => -6.0]);

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->carriedIn())->toBe(-6.0)
        ->and($ledger->annualEntitlement())->toBe(98.81);
})->note('Leave taken beyond the entitlement still has to be paid back.');

it('does not run away down the chain of years', function () {
    // Every year has a hand entered carry, so the chain never runs dry.
    foreach (range(2010, 2026) as $year) {
        LeaveCarryover::factory()->create(['leave_year' => $year, 'hours' => 1.0]);
    }

    expect((new LeaveLedger(new LeaveYear(2026)))->carriedIn())->toBe(1.0);
});
