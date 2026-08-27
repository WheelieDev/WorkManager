<?php

use App\Support\FlexiPeriod;

it('anchors period zero on Monday 10 August 2026', function () {
    $period = new FlexiPeriod(0);

    expect($period->start->format('D j M Y'))->toBe('Mon 10 Aug 2026')
        ->and($period->end->format('D j M Y'))->toBe('Sun 20 Sep 2026');
});

it('runs for exactly six weeks', function () {
    $period = new FlexiPeriod(0);

    expect((int) $period->start->diffInDays($period->end->copy()->startOfDay()) + 1)->toBe(42)
        ->and($period->weekCount())->toBe(6);
});

it('places the anchor date in period zero', function () {
    expect(FlexiPeriod::forDate(now()->parse('2026-08-10'))->index)->toBe(0);
});

it('places the last day of the period in period zero', function () {
    expect(FlexiPeriod::forDate(now()->parse('2026-09-20'))->index)->toBe(0);
});

it('starts the next period the day after the last', function () {
    $next = FlexiPeriod::forDate(now()->parse('2026-09-21'));

    expect($next->index)->toBe(1)
        ->and($next->start->format('D j M Y'))->toBe('Mon 21 Sep 2026');
});

it('resolves dates before the anchor to earlier periods', function () {
    $earlier = FlexiPeriod::forDate(now()->parse('2026-08-09'));

    expect($earlier->index)->toBe(-1)
        ->and($earlier->end->format('D j M Y'))->toBe('Sun 9 Aug 2026')
        ->and($earlier->start->format('D j M Y'))->toBe('Mon 29 Jun 2026');
});

it('steps between neighbouring periods', function () {
    $period = new FlexiPeriod(0);

    expect($period->next()->index)->toBe(1)
        ->and($period->previous()->index)->toBe(-1)
        ->and($period->next()->start->format('Y-m-d'))->toBe('2026-09-21');
});

it('knows which week of the period a date falls in', function () {
    $period = new FlexiPeriod(0);

    expect($period->weekNumberFor(now()->parse('2026-08-10')))->toBe(1)
        ->and($period->weekNumberFor(now()->parse('2026-08-16')))->toBe(1)
        ->and($period->weekNumberFor(now()->parse('2026-08-17')))->toBe(2)
        ->and($period->weekNumberFor(now()->parse('2026-09-20')))->toBe(6);
});

it('lists every date and every week start', function () {
    $period = new FlexiPeriod(0);

    expect($period->dates())->toHaveCount(42)
        ->and($period->weekStarts())->toHaveCount(6)
        ->and($period->weekStarts()->first()->format('Y-m-d'))->toBe('2026-08-10')
        ->and($period->weekStarts()->last()->format('Y-m-d'))->toBe('2026-09-14');
});

it('reports the period containing today as current', function () {
    $this->travelTo('2026-08-27');

    $period = FlexiPeriod::current();

    expect($period->index)->toBe(0)
        ->and($period->isCurrent())->toBeTrue()
        ->and($period->hasClosed())->toBeFalse();
});

it('counts only days up to today in an open period', function () {
    $this->travelTo('2026-08-27');

    expect((new FlexiPeriod(0))->countedThrough()->format('Y-m-d'))->toBe('2026-08-27');
});

it('counts every day of a period that has closed', function () {
    $this->travelTo('2026-10-01');

    $period = new FlexiPeriod(0);

    expect($period->hasClosed())->toBeTrue()
        ->and($period->countedThrough()->format('Y-m-d'))->toBe('2026-09-20');
});

it('counts nothing in a period that has not started', function () {
    $this->travelTo('2026-08-27');

    expect((new FlexiPeriod(1))->countedThrough())->toBeNull();
});
