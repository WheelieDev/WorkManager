<?php

use App\Enums\Weekday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Livewire\Livewire;

beforeEach(function () {
    config(['flexi.tracking_starts' => '2026-09-01']);

    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);

    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => '09:00',
            'end_time' => '16:30',
        ]);
    }
});

it('knows which dates it counts', function () {
    expect(FlexiPeriod::isTracked(now()->parse('2026-08-31')))->toBeFalse()
        ->and(FlexiPeriod::isTracked(now()->parse('2026-09-01')))->toBeTrue()
        ->and(FlexiPeriod::isTracked(now()->parse('2026-09-02')))->toBeTrue();
});

it('owes nothing for a working day before tracking begins', function () {
    // Monday 24 August, a day the pattern works and nothing was recorded.
    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-24')))->toBe(0.0);
})->note('Time off sick before the timesheet existed must not read as a deficit.');

it('owes the usual hours once tracking has begun', function () {
    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-09-07')))->toBe(-7.5);
});

it('banks nothing for hours worked before tracking begins', function () {
    WorkSession::factory()->on('2026-08-24', '09:00', '18:00')->create();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-24')))->toBe(0.0);
})->note('The window is closed in both directions, or it would be a loophole.');

it('counts only the tracked part of the period it starts in', function () {
    $this->travelTo('2026-09-04');

    // The period runs 10 August to 20 September, but only 1 to 4 September
    // counts. The 1st is a Tuesday, so that is four working days at 7.5
    // hours, none of them worked.
    expect((new FlexiCalculator)->accruedIn(new FlexiPeriod(0)))->toBe(-30.0);
});

it('accrues nothing in a period that ends before tracking begins', function () {
    $this->travelTo('2026-09-04');

    $earlier = new FlexiPeriod(-1);

    expect($earlier->countedFrom())->toBeNull()
        ->and((new FlexiCalculator)->accruedIn($earlier))->toBe(0.0);
});

it('carries nothing in from before tracking began', function () {
    $this->travelTo('2026-09-04');

    expect((new FlexiCalculator)->carryInto(new FlexiPeriod(0)))->toBe(0.0);
});

it('knows tracking begins part way through the first period', function () {
    expect((new FlexiPeriod(0))->startsPartWayThrough())->toBeTrue()
        ->and((new FlexiPeriod(1))->startsPartWayThrough())->toBeFalse();
});

it('counts everything when no start is set', function () {
    config(['flexi.tracking_starts' => null]);
    $this->travelTo('2026-08-25');

    expect(FlexiPeriod::isTracked(now()->parse('2020-01-01')))->toBeTrue()
        ->and((new FlexiCalculator)->deltaFor(now()->parse('2026-08-24')))->toBe(-7.5);
})->note('The start is a setting, not a hard rule baked into the maths.');

it('leaves untracked days out of the timesheet', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-27 10:00');

    Livewire::test('pages::timesheet')
        ->call('shift', -1)
        ->assertSee('No working days this week.');
})->note('Nothing to fill in before tracking began.');

it('still lets you look at dates before tracking began', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-09-04 10:00');

    Livewire::test('pages::timesheet')
        ->call('showDay', '2026-08-24')
        ->assertSee('Monday 24 August 2026')
        ->assertSee('Before tracking began');
})->note('The history has to stay reachable for annual leave later.');

it('says when tracking starts inside the period on view', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-09-04 10:00');

    Livewire::test('pages::timesheet')
        ->assertSee('Tracking starts 1 Sep 2026');
});
