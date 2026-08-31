<?php

use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkSession;
use App\Support\AreaTotals;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/*
|--------------------------------------------------------------------------
| Managing areas
|--------------------------------------------------------------------------
*/

it('creates an area', function () {
    Livewire::test('pages::areas.form')
        ->set('name', 'Licensing')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('areas.index'));

    expect(WorkArea::query()->sole()->name)->toBe('Licensing');
});

it('will not name two areas the same', function () {
    WorkArea::factory()->create(['name' => 'Licensing']);

    Livewire::test('pages::areas.form')
        ->set('name', 'Licensing')
        ->call('save')
        ->assertHasErrors('name');

    expect(WorkArea::query()->count())->toBe(1);
});

it('lets an area keep its own name when edited', function () {
    $area = WorkArea::factory()->create(['name' => 'Licensing']);

    Livewire::test('pages::areas.form', ['area' => $area])
        ->assertSet('name', 'Licensing')
        ->set('name', 'Licensing and taxis')
        ->call('save')
        ->assertHasNoErrors();

    expect($area->fresh()->name)->toBe('Licensing and taxis');
})->note('Editing without renaming must not trip the unique rule on itself.');

it('requires a name', function () {
    Livewire::test('pages::areas.form')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors('name');
});

/*
|--------------------------------------------------------------------------
| Archiving and deleting
|--------------------------------------------------------------------------
*/

it('archives an area and brings it back', function () {
    $area = WorkArea::factory()->create();

    Livewire::test('pages::areas.index')
        ->call('toggleArchived', $area)
        ->assertSee('Archived');

    expect($area->fresh()->isArchived())->toBeTrue();

    Livewire::test('pages::areas.index')->call('toggleArchived', $area);

    expect($area->fresh()->isArchived())->toBeFalse();
});

it('keeps an archived area out of the pickers', function () {
    WorkArea::factory()->create(['name' => 'Current work']);
    WorkArea::factory()->archived()->create(['name' => 'Finished work']);

    expect(WorkArea::query()->active()->pluck('name')->all())->toBe(['Current work']);
});

it('keeps the hours when an area is deleted', function () {
    $area = WorkArea::factory()->create();
    WorkSession::factory()->on('2026-08-27', '09:00', '12:00')->forArea($area)->create();

    Livewire::test('pages::areas.index')
        ->call('delete', $area)
        ->assertHasNoErrors();

    $session = WorkSession::query()->sole();

    expect(WorkArea::query()->count())->toBe(0)
        ->and($session->hours())->toBe(3.0)
        ->and($session->work_area_id)->toBeNull();
})->note('Deleting a label must never delete the time booked under it.');

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

it('adds up the hours booked to each area', function () {
    $licensing = WorkArea::factory()->create(['name' => 'Licensing']);
    $committee = WorkArea::factory()->create(['name' => 'Committee']);

    WorkSession::factory()->on('2026-08-25', '09:00', '12:00')->forArea($licensing)->create();
    WorkSession::factory()->on('2026-08-26', '09:00', '10:30')->forArea($licensing)->create();
    WorkSession::factory()->on('2026-08-26', '13:00', '15:00')->forArea($committee)->create();
    WorkSession::factory()->on('2026-08-27', '09:00', '17:00')->create();

    $totals = new AreaTotals;

    expect($totals->for($licensing))->toBe(4.5)
        ->and($totals->for($committee))->toBe(2.0)
        ->and($totals->total())->toBe(6.5);
})->note('The unassigned session is deliberately excluded: it has no area to count against.');

it('counts only the hours inside the given range', function () {
    $area = WorkArea::factory()->create();

    WorkSession::factory()->on('2026-08-01', '09:00', '12:00')->forArea($area)->create();
    WorkSession::factory()->on('2026-08-20', '09:00', '11:00')->forArea($area)->create();

    $totals = new AreaTotals(
        Carbon::parse('2026-08-15'),
        Carbon::parse('2026-08-31'),
    );

    expect($totals->for($area))->toBe(2.0);
});

it('ignores a running session, which has no hours yet', function () {
    $area = WorkArea::factory()->create();

    WorkSession::factory()->running()->forArea($area)->create();

    expect((new AreaTotals)->for($area))->toBe(0.0);
});

it('shows the hours on the index', function () {
    $area = WorkArea::factory()->create(['name' => 'Licensing']);
    WorkSession::factory()->on('2026-08-27', '09:00', '12:00')->forArea($area)->create();

    Livewire::test('pages::areas.index')
        ->assertSee('Licensing')
        ->assertSee('3.00');
});

it('renders the areas page with a link in the navigation', function () {
    WorkArea::factory()->create(['name' => 'Licensing']);

    $this->get(route('areas.index'))
        ->assertOk()
        ->assertSee('Work areas')
        ->assertSee('Licensing');
});

it('requires signing in to view areas', function () {
    auth()->logout();

    $this->get(route('areas.index'))->assertRedirect(route('login'));
});
