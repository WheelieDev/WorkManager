<?php

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
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

    expect((new SicknessLedger('2026-06-10'))->used())->toBe(0.0)
        ->and((new SicknessLedger('2026-06-11'))->used())->toBe(4.0);
})->note('The policy counts the twelve months preceding the first day, not including it.');

it('counts only sickness, not other absence', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    foreach ([LeaveType::Annual, LeaveType::Hospital, LeaveType::Disability] as $type) {
        LeaveDay::factory()->create(['date' => '2026-06-10', 'type' => $type, 'hours' => 4]);
    }

    expect((new SicknessLedger)->used())->toBe(0.0);
});

it('does not draw down sick pay for a month the pattern does not work', function () {
    config(['leave.service_started' => '2020-01-01']);
    $this->travelTo('2026-08-29');

    // The pattern works every month but August, so an August absence costs
    // nothing while an otherwise identical July one is paid for.
    LeaveDay::factory()->create(['date' => '2026-08-12', 'type' => LeaveType::Sickness, 'hours' => 4]);

    expect((new SicknessLedger)->used())->toBe(0.0);

    LeaveDay::factory()->create(['date' => '2026-07-15', 'type' => LeaveType::Sickness, 'hours' => 4]);

    expect((new SicknessLedger)->used())->toBe(4.0);
})->note('A day off contract cannot draw down an entitlement it never earned.');

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

/*
|--------------------------------------------------------------------------
| Saying which year of service it is
|--------------------------------------------------------------------------
*/

it('counts the year being served, not the years completed', function (string $started, int $year, string $label) {
    config(['leave.service_started' => $started]);
    $this->travelTo('2026-08-29');

    $ledger = new SicknessLedger;

    expect($ledger->yearOfService())->toBe($year)
        ->and($ledger->yearOfServiceLabel())->toBe($label);
})->with([
    'part way through the first' => ['2026-06-01', 1, '1st year of service'],
    'one complete year' => ['2025-08-29', 2, '2nd year of service'],
    'two complete years' => ['2024-08-29', 3, '3rd year of service'],
    'three complete years' => ['2023-08-29', 4, '4th year of service'],
    'four complete years' => ['2022-08-29', 5, '5th year of service'],
    'five complete years' => ['2021-08-29', 6, '6th year of service'],
    'twenty complete years' => ['2006-08-29', 21, '21st year of service'],
])->note('The policy bands by the year being served, so three years in is the fourth year.');

it('matches the band to the year of service the policy names', function () {
    config(['leave.service_started' => '2023-08-29']);
    $this->travelTo('2026-08-29');

    $ledger = new SicknessLedger;

    // "During the 4th and 5th Year of Service: 5 months full and 5 months half."
    expect($ledger->yearOfServiceLabel())->toBe('4th year of service')
        ->and($ledger->band()->label())->toBe('5 months full pay and 5 months half pay');
});

/*
|--------------------------------------------------------------------------
| A rolling year, not a leave year
|--------------------------------------------------------------------------
*/

it('runs its window back from the day the absence begins', function () {
    $ledger = new SicknessLedger('2026-08-29');

    expect($ledger->windowStart()->toDateString())->toBe('2025-08-29')
        ->and($ledger->windowEnd()->toDateString())->toBe('2026-08-28')
        ->and($ledger->windowLabel())->toBe('29 Aug 2025 - 28 Aug 2026');
})->note('The window ends the day before, because the policy counts what precedes the absence.');

it('moves the whole window when the absence begins on another day', function () {
    expect((new SicknessLedger('2026-08-29'))->windowStart()->toDateString())->toBe('2025-08-29')
        ->and((new SicknessLedger('2027-02-01'))->windowStart()->toDateString())->toBe('2026-02-01');
})->note('A leave year would not move; a rolling year does.');

it('ignores the leave year boundary entirely', function () {
    config(['leave.service_started' => '2020-01-01']);

    // The leave year turns over on 1 April, so these sit either side of it.
    foreach (['2026-03-25', '2026-04-08'] as $date) {
        LeaveDay::factory()->create([
            'date' => $date,
            'type' => LeaveType::Sickness,
            'hours' => 4,
        ]);
    }

    // Both are inside the twelve months before this day, so both count.
    expect((new SicknessLedger('2026-06-10'))->used())->toBe(8.0);
})->note('Sickness spanning the turn of the leave year is still one running total.');

it('lists the sickness its window counts', function () {
    config(['leave.service_started' => '2020-01-01']);

    LeaveRequest::factory()
        ->create(['starts_on' => '2026-06-10', 'ends_on' => '2026-06-10'])
        ->days()->create(['date' => '2026-06-10', 'type' => LeaveType::Sickness, 'hours' => 4]);

    LeaveRequest::factory()
        ->create(['starts_on' => '2024-06-10', 'ends_on' => '2024-06-10'])
        ->days()->create(['date' => '2024-06-10', 'type' => LeaveType::Sickness, 'hours' => 4]);

    $requests = (new SicknessLedger('2026-08-29'))->requests();

    expect($requests)->toHaveCount(1)
        ->and($requests->first()->starts_on->toDateString())->toBe('2026-06-10');
})->note('The list and the balance must agree on which absences count.');

it('leaves other kinds of absence out of its list', function () {
    LeaveRequest::factory()
        ->create(['starts_on' => '2026-06-10', 'ends_on' => '2026-06-10'])
        ->days()->create(['date' => '2026-06-10', 'type' => LeaveType::Annual, 'hours' => 4]);

    expect((new SicknessLedger('2026-08-29'))->requests())->toBeEmpty();
});
