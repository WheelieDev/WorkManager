<?php

use App\Enums\LeaveType;
use App\Enums\Weekday;
use App\Models\LeaveDay;
use App\Models\User;
use App\Models\WorkPattern;
use Livewire\Livewire;

beforeEach(function () {
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

it('books a phased return against a day', function () {
    Livewire::test('pages::timesheet')
        ->call('editPhasedReturn', '2026-08-25')
        ->assertSet('phasedExists', false)
        ->set('phased_hours', '3.5')
        ->call('savePhasedReturn')
        ->assertSet('showingPhasedForm', false);

    $this->assertDatabaseHas('leave_days', [
        'date' => '2026-08-25',
        'type' => LeaveType::PhasedReturn->value,
        'hours' => 3.5,
    ]);
});

it('offsets the expected hours by the booked amount', function () {
    LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();

    Livewire::test('pages::timesheet')
        ->call('showDay', '2026-08-25')
        ->assertSee('0.00 / 4.00')
        ->assertSee('Phased return 3.50h');
});

it('updates the booked hours rather than adding a second entry', function () {
    LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();

    Livewire::test('pages::timesheet')
        ->call('editPhasedReturn', '2026-08-25')
        ->assertSet('phasedExists', true)
        ->assertSet('phased_hours', '3.5')
        ->set('phased_hours', '2')
        ->call('savePhasedReturn');

    $this->assertDatabaseCount('leave_days', 1);
    $this->assertDatabaseHas('leave_days', [
        'date' => '2026-08-25',
        'type' => LeaveType::PhasedReturn->value,
        'hours' => 2.0,
    ]);
});

it('moves the entry when its date is edited', function () {
    LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();

    Livewire::test('pages::timesheet')
        ->call('editPhasedReturn', '2026-08-25')
        ->set('phased_date', '2026-08-26')
        ->call('savePhasedReturn');

    $this->assertDatabaseCount('leave_days', 1);
    $this->assertDatabaseHas('leave_days', [
        'date' => '2026-08-26',
        'type' => LeaveType::PhasedReturn->value,
        'hours' => 3.5,
    ]);
});

it('removes a phased return', function () {
    $day = LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();

    Livewire::test('pages::timesheet')
        ->call('editPhasedReturn', '2026-08-25')
        ->call('removePhasedReturn')
        ->assertSet('showingPhasedForm', false);

    $this->assertModelMissing($day);
});

it('rejects an offset that is not a usable number of hours', function (string $hours, string $rule) {
    Livewire::test('pages::timesheet')
        ->call('editPhasedReturn', '2026-08-25')
        ->set('phased_hours', $hours)
        ->call('savePhasedReturn')
        ->assertHasErrors(['phased_hours' => $rule]);

    $this->assertDatabaseCount('leave_days', 0);
})->with([
    'empty' => ['', 'required'],
    'zero' => ['0', 'gt'],
    'more than a day' => ['25', 'max'],
    'not a number' => ['three', 'numeric'],
]);

it('names the phased return separately from leave booked alongside it', function () {
    LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();
    LeaveDay::factory()->on('2026-08-25', 2)->create();

    Livewire::test('pages::timesheet')
        ->call('showDay', '2026-08-25')
        ->assertSee('Annual leave 2.00h')
        ->assertSee('Phased return 3.50h');
});

it('grounds a phased return day in the other colour', function () {
    LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();

    Livewire::test('pages::timesheet')
        ->call('showWeek', '2026-08-24')
        ->assertSee('Phased 3.50h')
        ->assertSeeHtml('bg-pot-other/[0.08]');
});

it('lets annual leave outrank a phased return on the same day', function () {
    LeaveDay::factory()->phasedReturn()->on('2026-08-25', 3.5)->create();
    LeaveDay::factory()->on('2026-08-25', 2)->create();

    Livewire::test('pages::timesheet')
        ->call('showWeek', '2026-08-24')
        ->assertSeeHtml('bg-pot-annual/[0.08]')
        ->assertDontSeeHtml('bg-pot-other/[0.08]');
});
