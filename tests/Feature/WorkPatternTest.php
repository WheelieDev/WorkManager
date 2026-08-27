<?php

use App\Enums\Weekday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkPatternDay;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('treats the newest started pattern as the one in effect', function () {
    $older = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    $newer = WorkPattern::factory()->create(['start_date' => '2026-06-01']);

    $this->travelTo('2026-08-01');

    expect(WorkPattern::inEffectOn(now())->id)->toBe($newer->id)
        ->and($newer->isCurrent())->toBeTrue()
        ->and($older->isCurrent())->toBeFalse();
});

it('ignores patterns that have not started yet', function () {
    $current = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    $future = WorkPattern::factory()->create(['start_date' => '2026-12-01']);

    $this->travelTo('2026-08-01');

    expect(WorkPattern::inEffectOn(now())->id)->toBe($current->id)
        ->and($future->isCurrent())->toBeFalse();
});

it('treats a pattern starting today as in effect', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => now()->toDateString()]);

    expect(WorkPattern::inEffectOn(now())?->id)->toBe($pattern->id)
        ->and($pattern->isCurrent())->toBeTrue();
});

it('stores the start date without a time component', function () {
    WorkPattern::factory()->create(['start_date' => '2026-09-01']);

    expect(DB::table('work_patterns')->value('start_date'))->toBe('2026-09-01');
});

it('has no pattern in effect before the earliest start date', function () {
    WorkPattern::factory()->create(['start_date' => '2026-06-01']);

    expect(WorkPattern::inEffectOn(now()->parse('2026-01-01')))->toBeNull();
});

it('calculates hours for a day', function () {
    $day = WorkPatternDay::factory()->create(['start_time' => '09:00', 'end_time' => '17:30']);

    expect($day->hours())->toBe(8.5)
        ->and($day->isWorked())->toBeTrue();
});

it('counts an unworked day as zero hours', function () {
    $day = WorkPatternDay::factory()->notWorked()->create();

    expect($day->hours())->toBe(0.0)
        ->and($day->isWorked())->toBeFalse();
});

it('totals the weekly hours across the pattern', function () {
    $pattern = WorkPattern::factory()->nineToFive()->create();

    expect($pattern->weeklyHours())->toBe(40.0);
});

it('creates a pattern with only the selected days worked', function () {
    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->set('days.1.worked', true)
        ->set('days.1.start_time', '09:00')
        ->set('days.1.end_time', '17:00')
        ->set('days.2.worked', true)
        ->set('days.2.start_time', '09:00')
        ->set('days.2.end_time', '17:30')
        ->set('days.3.worked', false)
        ->set('days.4.worked', false)
        ->set('days.5.worked', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('patterns.index'));

    $pattern = WorkPattern::query()->sole();

    expect($pattern->start_date->toDateString())->toBe('2026-09-01')
        ->and($pattern->days)->toHaveCount(5)
        ->and($pattern->weeklyHours())->toBe(16.5);

    $monday = $pattern->days->firstWhere('weekday', Weekday::Monday);
    expect($monday->start_time->format('H:i'))->toBe('09:00');

    $friday = $pattern->days->firstWhere('weekday', Weekday::Friday);
    expect($friday->isWorked())->toBeFalse();
});

it('defaults a new pattern to every weekday switched on', function () {
    Livewire::test('pages::patterns.form')
        ->assertSet('days.1.worked', true)
        ->assertSet('days.5.worked', true)
        ->assertSet('days.1.start_time', '09:00');
});

it('reflects which days an existing pattern works', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => '2026-09-01']);

    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create([
            'weekday' => $weekday,
            'start_time' => $weekday === Weekday::Wednesday ? null : '09:00',
            'end_time' => $weekday === Weekday::Wednesday ? null : '17:00',
        ]);
    }

    Livewire::test('pages::patterns.form', ['pattern' => $pattern])
        ->assertSet('days.1.worked', true)
        ->assertSet('days.3.worked', false)
        ->assertSet('days.5.worked', true);
});

it('clears the times when a day is switched off', function () {
    $pattern = WorkPattern::factory()->nineToFive()->create(['start_date' => '2026-09-01']);

    Livewire::test('pages::patterns.form', ['pattern' => $pattern])
        ->set('days.3.worked', false)
        ->call('save')
        ->assertHasNoErrors();

    $wednesday = $pattern->fresh()->days->firstWhere('weekday', Weekday::Wednesday);

    expect($wednesday->isWorked())->toBeFalse()
        ->and($wednesday->start_time)->toBeNull()
        ->and($pattern->fresh()->weeklyHours())->toBe(32.0);
});

it('swaps the time inputs for a not worked label when a day is switched off', function () {
    Livewire::test('pages::patterns.form')
        ->assertDontSee('Not worked')
        ->set('days.3.worked', false)
        ->assertSee('Not worked');
});

it('allows a pattern with a single day worked', function () {
    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->set('days.1.worked', true)
        ->set('days.1.start_time', '10:00')
        ->set('days.1.end_time', '14:00')
        ->set('days.2.worked', false)
        ->set('days.3.worked', false)
        ->set('days.4.worked', false)
        ->set('days.5.worked', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(WorkPattern::query()->sole()->weeklyHours())->toBe(4.0);
});

it('updates an existing pattern rather than creating another', function () {
    $pattern = WorkPattern::factory()->nineToFive()->create(['start_date' => '2026-09-01']);

    Livewire::test('pages::patterns.form', ['pattern' => $pattern])
        ->assertSet('start_date', '2026-09-01')
        ->assertSet('days.1.worked', true)
        ->assertSet('days.1.start_time', '09:00')
        ->set('days.1.end_time', '13:00')
        ->call('save')
        ->assertHasNoErrors();

    expect(WorkPattern::query()->count())->toBe(1)
        ->and($pattern->fresh()->weeklyHours())->toBe(36.0);
});

it('rejects a duplicate start date', function () {
    WorkPattern::factory()->create(['start_date' => '2026-09-01']);

    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->call('save')
        ->assertHasErrors(['start_date' => 'unique']);
});

it('allows an existing pattern to keep its own start date', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => '2026-09-01']);

    Livewire::test('pages::patterns.form', ['pattern' => $pattern])
        ->call('save')
        ->assertHasNoErrors();
});

it('requires an end time after the start time', function () {
    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->set('days.1.worked', true)
        ->set('days.1.start_time', '17:00')
        ->set('days.1.end_time', '09:00')
        ->call('save')
        ->assertHasErrors('days.1.end_time');

    expect(WorkPattern::query()->count())->toBe(0);
});

it('requires times for a day that is switched on', function () {
    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->set('days.1.worked', true)
        ->set('days.1.start_time', '')
        ->call('save')
        ->assertHasErrors('days.1.start_time');

    expect(WorkPattern::query()->count())->toBe(0);
});

it('ignores times left on a day that is switched off', function () {
    Livewire::test('pages::patterns.form')
        ->set('start_date', '2026-09-01')
        ->set('days.1.worked', false)
        ->set('days.1.start_time', '')
        ->set('days.1.end_time', '')
        ->call('save')
        ->assertHasNoErrors();

    $monday = WorkPattern::query()->sole()->days->firstWhere('weekday', Weekday::Monday);

    expect($monday->isWorked())->toBeFalse();
});

it('lists patterns newest first and flags the current one', function () {
    WorkPattern::factory()->nineToFive()->create(['start_date' => '2026-01-01']);
    WorkPattern::factory()->nineToFive()->create(['start_date' => '2026-06-01']);

    $this->travelTo('2026-08-01');

    Livewire::test('pages::patterns.index')
        ->assertSeeInOrder(['1 Jun 2026', '1 Jan 2026'])
        ->assertSee('Current');
});

it('deletes a pattern and its days', function () {
    $pattern = WorkPattern::factory()->nineToFive()->create();

    Livewire::test('pages::patterns.index')
        ->call('delete', $pattern)
        ->assertHasNoErrors();

    expect(WorkPattern::query()->count())->toBe(0)
        ->and(WorkPatternDay::query()->count())->toBe(0);
});

it('requires signing in to view patterns', function () {
    auth()->logout();

    $this->get(route('patterns.index'))->assertRedirect(route('login'));
});
