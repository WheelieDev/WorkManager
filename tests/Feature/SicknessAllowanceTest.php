<?php

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Support\SicknessLedger;

beforeEach(function () {
    partTimePattern();

    // 16h a week over 52.14 weeks is 69.52h in a month of pay.
    $this->monthHours = 69.52;
});

/*
|--------------------------------------------------------------------------
| Length of service
|--------------------------------------------------------------------------
*/

it('cannot work out an entitlement until service is known', function () {
    config(['leave.service_started' => null]);

    $ledger = new SicknessLedger;

    expect($ledger->isConfigured())->toBeFalse()
        ->and($ledger->band())->toBeNull()
        ->and($ledger->monthsOfService())->toBeNull()
        ->and($ledger->fullPayEntitlement())->toBe(0.0)
        ->and($ledger->halfPayEntitlement())->toBe(0.0);
})->note('Guessing a start date would put a wrong number in front of someone who is off sick.');

it('counts completed months and years of service', function () {
    config(['leave.service_started' => '2020-01-15']);
    $this->travelTo('2026-08-29');

    $ledger = new SicknessLedger;

    expect($ledger->monthsOfService())->toBe(79)
        ->and($ledger->yearsOfService())->toBe(6);
});

it('treats service as nothing before it has begun', function () {
    config(['leave.service_started' => '2027-01-01']);
    $this->travelTo('2026-08-29');

    expect((new SicknessLedger)->monthsOfService())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The bands, as the policy states them
|--------------------------------------------------------------------------
*/

it('gives one month full pay and no half pay in the first four months', function () {
    config(['leave.service_started' => '2026-06-01']);
    $this->travelTo('2026-08-29');

    $band = (new SicknessLedger)->band();

    expect($band->fullPayMonths)->toBe(1.0)
        ->and($band->halfPayMonths)->toBe(0.0);
})->note('Half pay only arrives after four months of service.');

it('adds two months half pay once four months are served', function () {
    config(['leave.service_started' => '2026-04-01']);
    $this->travelTo('2026-08-29');

    $band = (new SicknessLedger)->band();

    expect($band->fullPayMonths)->toBe(1.0)
        ->and($band->halfPayMonths)->toBe(2.0);
});

it('bands the entitlement by year of service', function (string $started, float $full, float $half) {
    config(['leave.service_started' => $started]);
    $this->travelTo('2026-08-29');

    $band = (new SicknessLedger)->band();

    expect($band->fullPayMonths)->toBe($full)
        ->and($band->halfPayMonths)->toBe($half);
})->with([
    'second year' => ['2025-08-29', 2.0, 2.0],
    'third year' => ['2024-08-29', 4.0, 4.0],
    'fourth year' => ['2023-08-29', 5.0, 5.0],
    'fifth year' => ['2022-08-29', 5.0, 5.0],
    'after five years' => ['2021-08-29', 6.0, 6.0],
    'long service' => ['2000-01-01', 6.0, 6.0],
]);

/*
|--------------------------------------------------------------------------
| What a month of pay is worth
|--------------------------------------------------------------------------
*/

it('values a month of pay at the hours the pattern works', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    // 16h a week x (52.14 / 12).
    expect((new SicknessLedger)->monthHours())->toBe(69.52);
});

it('turns the banded months into hours', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    $ledger = new SicknessLedger;

    expect($ledger->fullPayEntitlement())->toBe(round(6 * $this->monthHours, 2))
        ->and($ledger->halfPayEntitlement())->toBe(round(6 * $this->monthHours, 2));
});

/*
|--------------------------------------------------------------------------
| The rolling twelve months
|--------------------------------------------------------------------------
*/

it('reduces the entitlement by sickness in the preceding twelve months', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    // A working Wednesday, four hours at this pattern.
    LeaveDay::factory()->create([
        'date' => '2026-06-10',
        'type' => LeaveType::Sickness,
        'hours' => 4,
    ]);

    $ledger = new SicknessLedger;

    expect($ledger->used())->toBe(4.0)
        ->and($ledger->fullPayRemaining())->toBe(round(6 * $this->monthHours - 4, 2));
});

it('ignores sickness older than the window', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    LeaveDay::factory()->create([
        'date' => '2025-06-10',
        'type' => LeaveType::Sickness,
        'hours' => 4,
    ]);

    expect((new SicknessLedger)->used())->toBe(0.0);
})->note('The window rolls, so an old absence stops counting once a year has passed.');

it('excludes the first day of the absence from its own window', function () {
    config(['leave.service_started' => '2020-01-01']);

    LeaveDay::factory()->create([
        'date' => '2026-06-10',
        'type' => LeaveType::Sickness,
        'hours' => 4,
    ]);

    expect((new SicknessLedger)->used(now()->parse('2026-06-10')))->toBe(0.0)
        ->and((new SicknessLedger)->used(now()->parse('2026-06-11')))->toBe(4.0);
})->note('The policy counts the twelve months preceding the first day, not including it.');

it('counts only sickness, not other absence', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    foreach ([LeaveType::Annual, LeaveType::Hospital, LeaveType::Disability] as $type) {
        LeaveDay::factory()->create(['date' => '2026-06-10', 'type' => $type, 'hours' => 4]);
    }

    expect((new SicknessLedger)->used())->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Spending full pay before half pay
|--------------------------------------------------------------------------
*/

it('spends full pay before it touches half pay', function () {
    config(['leave.service_started' => '2026-04-01']);
    $this->travelTo('2026-08-29');

    // One month full pay, then two months half pay. Use a month and a half.
    $used = round(1.5 * $this->monthHours, 2);

    LeaveDay::factory()->create([
        'date' => '2026-06-10',
        'type' => LeaveType::Sickness,
        'hours' => $used,
    ]);

    $ledger = new SicknessLedger;

    // The booking is capped at the hours the day actually works.
    expect($ledger->used())->toBe(4.0)
        ->and($ledger->fullPayRemaining())->toBe(round($this->monthHours - 4, 2))
        ->and($ledger->halfPayRemaining())->toBe(round(2 * $this->monthHours, 2));
})->note('A day cannot cost more than it works, however many hours are entered.');

it('eats into half pay once full pay is gone', function () {
    config(['leave.service_started' => '2026-04-01']);
    $this->travelTo('2027-03-01');

    // Enough working days to exhaust one month of full pay and more.
    $date = now()->parse('2026-09-01');
    $booked = 0.0;

    while ($booked < $this->monthHours + 8) {
        if ($date->isWeekday() && ! $date->isFriday()) {
            LeaveDay::factory()->create([
                'date' => $date->toDateString(),
                'type' => LeaveType::Sickness,
                'hours' => 4,
            ]);

            $booked += 4;
        }

        $date = $date->addDay();
    }

    $ledger = new SicknessLedger;

    expect($ledger->fullPayRemaining())->toBe(0.0)
        ->and($ledger->halfPayRemaining())->toBeLessThan(2 * $this->monthHours)
        ->and($ledger->halfPayRemaining())->toBeGreaterThan(0.0);
});

it('never reports a negative entitlement', function () {
    config(['leave.service_started' => '2026-06-01']);
    $this->travelTo('2027-03-01');

    // First four months of service: one month full pay and no half pay at all.
    $date = now()->parse('2026-09-01');

    for ($i = 0; $i < 120; $i++) {
        if ($date->isWeekday() && ! $date->isFriday()) {
            LeaveDay::factory()->create([
                'date' => $date->toDateString(),
                'type' => LeaveType::Sickness,
                'hours' => 4,
            ]);
        }

        $date = $date->addDay();
    }

    $ledger = new SicknessLedger;

    expect($ledger->fullPayRemaining())->toBe(0.0)
        ->and($ledger->halfPayRemaining())->toBe(0.0)
        ->and($ledger->isExhausted())->toBeTrue();
});
