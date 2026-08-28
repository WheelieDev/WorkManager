<?php

use App\Models\BankHoliday;
use App\Models\LeaveDay;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;

/*
|--------------------------------------------------------------------------
| The leave year
|--------------------------------------------------------------------------
*/

it('runs the leave year from 1 April to 31 March', function () {
    $year = new LeaveYear(2026);

    expect($year->start->toDateString())->toBe('2026-04-01')
        ->and($year->end->toDateString())->toBe('2027-03-31')
        ->and($year->label())->toBe('1 Apr 2026 - 31 Mar 2027');
});

it('puts a date before April in the previous leave year', function () {
    expect(LeaveYear::forDate(now()->parse('2027-03-31'))->year)->toBe(2026)
        ->and(LeaveYear::forDate(now()->parse('2027-04-01'))->year)->toBe(2027);
});

/*
|--------------------------------------------------------------------------
| Entitlements
|--------------------------------------------------------------------------
*/

it('works out the full time year from 37.5 hours over 52.14 weeks', function () {
    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->fullTimeYearlyHours())->toBe(1955.25);
});

it('pro-rata s the pattern against a full time week', function () {
    partTimePattern();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->weeklyHours())->toBe(16.0)
        ->and($ledger->fraction())->toBe(0.4267)
        ->and($ledger->yearlyHours())->toBe(834.24);
});

it('gives the public holiday allowance in full, whatever the paid weeks', function () {
    partTimePattern();

    // 8 days x 7.5h x 0.4267. Bank holidays fall when they fall.
    expect((new LeaveLedger(new LeaveYear(2026)))->publicHolidayEntitlement())->toBe(25.6);
});

it('pro-rata s the public holiday allowance too when asked to', function () {
    partTimePattern();
    config(['leave.prorate_public_holidays' => true]);

    expect((new LeaveLedger(new LeaveYear(2026)))->publicHolidayEntitlement())->toBe(23.47);
});

it('pro-rata s the bookable entitlement by the weeks paid', function () {
    partTimePattern();

    // 27 days x 7.5h x 0.4267 x (47.8 / 52.14)
    expect((new LeaveLedger(new LeaveYear(2026)))->baseEntitlement())->toBe(79.21);
});

it('earns leave only for the weeks it is paid', function () {
    partTimePattern();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->contractedWeeks())->toBe(47.8)
        ->and($ledger->yearFraction())->toBe(0.916763);
})->note('August is unpaid, so those weeks earn no leave.');

it('uses an awarded figure instead of the calculation when one is set', function () {
    partTimePattern();
    config(['leave.annual_leave_hours' => 84.5]);

    expect((new LeaveLedger(new LeaveYear(2026)))->baseEntitlement())->toBe(84.5);
});

it('has no entitlement without a pattern', function () {
    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->fraction())->toBe(0.0)
        ->and($ledger->publicHolidayEntitlement())->toBe(0.0)
        ->and($ledger->baseEntitlement())->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Public holidays
|--------------------------------------------------------------------------
*/

it('charges a bank holiday only when it lands on a working day', function () {
    partTimePattern();

    BankHoliday::factory()->on('2026-12-28', 'Boxing Day')->create();   // Monday
    BankHoliday::factory()->on('2026-12-25', 'Christmas Day')->create(); // Friday, not worked

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->publicHolidaysUsed())->toBe(4.0);
});

it('charges nothing for a bank holiday in a month that is not worked', function () {
    partTimePattern();

    BankHoliday::factory()->on('2026-08-31', 'Summer bank holiday')->create(); // Monday in August

    expect((new LeaveLedger(new LeaveYear(2026)))->publicHolidaysUsed())->toBe(0.0);
});

it('adds the unspent allowance to bookable leave', function () {
    partTimePattern();
    BankHoliday::factory()->on('2026-12-28')->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    // 25.60h allowance, 4.00h taken by the one Monday.
    expect($ledger->publicHolidayAdjustment())->toBe(21.6)
        ->and($ledger->publicHolidaySurplus())->toBe(21.6)
        ->and($ledger->publicHolidayShortfall())->toBe(0.0)
        ->and($ledger->annualEntitlement())->toBe(100.81);
})->note('Surplus public holiday hours end up in the leave balance.');

it('takes the shortfall out of bookable leave', function () {
    partTimePattern();

    // Seven Mondays of bank holiday at 4h is 28h, against a 25.6h allowance.
    foreach (['2026-04-06', '2026-05-04', '2026-05-25', '2026-12-28', '2027-01-04', '2027-02-01', '2027-03-01'] as $date) {
        BankHoliday::factory()->on($date)->create();
    }

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->publicHolidaysUsed())->toBe(28.0)
        ->and($ledger->publicHolidaySurplus())->toBe(0.0)
        ->and($ledger->publicHolidayShortfall())->toBe(2.4)
        ->and($ledger->annualEntitlement())->toBe(76.81)
        ->and($ledger->annualRemaining())->toBe(76.81);
})->note('This is the number a part time worker actually wants: leave spent on bank holidays.');

/*
|--------------------------------------------------------------------------
| Bookable leave
|--------------------------------------------------------------------------
*/

it('draws booked leave from the bookable pot', function () {
    partTimePattern();

    LeaveDay::factory()->on('2026-09-07', 4.0)->create();
    LeaveDay::factory()->on('2026-09-08', 4.0)->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->annualBooked())->toBe(8.0)
        ->and($ledger->annualEntitlement())->toBe(104.81)
        ->and($ledger->annualRemaining())->toBe(96.81);
});

it('ignores leave booked in another leave year', function () {
    partTimePattern();

    LeaveDay::factory()->on('2027-04-02', 4.0)->create();

    expect((new LeaveLedger(new LeaveYear(2026)))->annualBooked())->toBe(0.0);
});

it('knows when more leave has been used than awarded', function () {
    partTimePattern();

    // Entitlement is 104.81h, or about 26 working days at four hours.
    $date = now()->parse('2026-09-07');

    for ($booked = 0; $booked < 30; $date = $date->addDay()) {
        if ($date->dayOfWeekIso >= 5) {
            continue;
        }

        LeaveDay::factory()->on($date->toDateString(), 4.0)->create();
        $booked++;
    }

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->annualBooked())->toBe(120.0)
        ->and($ledger->isOverdrawn())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| How leave meets the flexi balance
|--------------------------------------------------------------------------
*/

it('expects nothing on a full day of leave', function () {
    partTimePattern();
    LeaveDay::factory()->on('2026-09-07', 4.0)->create();

    $calculator = new FlexiCalculator;

    expect($calculator->patternHours(now()->parse('2026-09-07')))->toBe(4.0)
        ->and($calculator->expectedHours(now()->parse('2026-09-07')))->toBe(0.0)
        ->and($calculator->deltaFor(now()->parse('2026-09-07')))->toBe(0.0);
})->note('Leave neither owes nor banks anything against the flexi balance.');

it('still expects the rest of the day on part day leave', function () {
    partTimePattern();
    LeaveDay::factory()->on('2026-09-07', 2.0)->create();

    $calculator = new FlexiCalculator;

    expect($calculator->expectedHours(now()->parse('2026-09-07')))->toBe(2.0)
        ->and($calculator->deltaFor(now()->parse('2026-09-07')))->toBe(-2.0);
});

it('balances a part day of leave against the hours actually worked', function () {
    partTimePattern();
    LeaveDay::factory()->on('2026-09-07', 2.0)->create();
    WorkSession::factory()->on('2026-09-07', '09:00', '11:00')->create();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-09-07')))->toBe(0.0);
});

it('never lets leave cover more than the day asked for', function () {
    partTimePattern();
    LeaveDay::factory()->on('2026-09-07', 9.0)->create();

    $calculator = new FlexiCalculator;

    expect($calculator->coveredHours(now()->parse('2026-09-07')))->toBe(4.0)
        ->and($calculator->expectedHours(now()->parse('2026-09-07')))->toBe(0.0);
})->note('Over-booking a day must not create a surplus.');

it('does not cover a day the pattern never asked for', function () {
    partTimePattern();
    LeaveDay::factory()->on('2026-09-11', 4.0)->create(); // a Friday, not worked

    expect((new FlexiCalculator)->coveredHours(now()->parse('2026-09-11')))->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Nothing counted twice
|--------------------------------------------------------------------------
*/

it('does not charge for leave sitting on a bank holiday', function () {
    partTimePattern();
    BankHoliday::factory()->on('2026-12-28', 'Boxing Day')->create();
    LeaveDay::factory()->on('2026-12-28', 4.0)->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->publicHolidaysUsed())->toBe(4.0)
        ->and($ledger->annualBooked())->toBe(0.0)
        ->and($ledger->annualRemaining())->toBe($ledger->annualEntitlement());
})->note('The day is already paid for by the public holiday allowance.');

it('says why a booking on a bank holiday costs nothing', function () {
    partTimePattern();
    BankHoliday::factory()->on('2026-12-28')->create();
    $leave = LeaveDay::factory()->on('2026-12-28', 4.0)->create();

    expect((new LeaveLedger(new LeaveYear(2026)))->bookingNote($leave))->toBe('already a bank holiday');
});

it('never charges more than a day is worth', function () {
    partTimePattern();
    $leave = LeaveDay::factory()->on('2026-09-08', 9.0)->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->effectiveHours($leave))->toBe(4.0)
        ->and($ledger->annualBooked())->toBe(4.0)
        ->and($ledger->bookingNote($leave))->toBe('more than the day works');
});

it('charges nothing for leave on a day never worked', function () {
    partTimePattern();
    $leave = LeaveDay::factory()->on('2026-09-11', 4.0)->create(); // a Friday

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->annualBooked())->toBe(0.0)
        ->and($ledger->bookingNote($leave))->toBe('not a working day');
});

it('counts an ordinary booking in full and says nothing about it', function () {
    partTimePattern();
    $leave = LeaveDay::factory()->on('2026-09-08', 4.0)->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));

    expect($ledger->annualBooked())->toBe(4.0)
        ->and($ledger->bookingNote($leave))->toBeNull();
});

it('counts a bank holiday once, not once per pot', function () {
    partTimePattern();
    BankHoliday::factory()->on('2026-12-28')->create();

    $ledger = new LeaveLedger(new LeaveYear(2026));
    $calculator = new FlexiCalculator;
    $date = now()->parse('2026-12-28');

    // Charged to the public holiday allowance, and nowhere else.
    expect($ledger->publicHolidaysUsed())->toBe(4.0)
        ->and($ledger->annualBooked())->toBe(0.0)
        ->and($calculator->expectedHours($date))->toBe(0.0)
        ->and($calculator->deltaFor($date))->toBe(0.0);
})->note('One day off, one deduction.');
