<?php

use App\Enums\Weekday;
use App\Models\BankHoliday;
use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    // These cover the balance mechanics themselves, so they run with no
    // tracking start; the start date has its own tests.
    config(['flexi.tracking_starts' => null]);
});

/**
 * A feed response in the shape gov.uk returns.
 */
function bankHolidayFeed(): array
{
    return [
        'england-and-wales' => [
            'division' => 'england-and-wales',
            'events' => [
                ['title' => 'Summer bank holiday', 'date' => '2026-08-31', 'notes' => '', 'bunting' => true],
                ['title' => 'Christmas Day', 'date' => '2026-12-25', 'notes' => '', 'bunting' => true],
                ['title' => 'Boxing Day', 'date' => '2026-12-28', 'notes' => 'Substitute day', 'bunting' => true],
            ],
        ],
        'scotland' => [
            'division' => 'scotland',
            'events' => [
                ['title' => 'St Andrew’s Day', 'date' => '2026-11-30', 'notes' => '', 'bunting' => true],
            ],
        ],
    ];
}

it('syncs England and Wales holidays from the feed', function () {
    Http::fake(['www.gov.uk/*' => Http::response(bankHolidayFeed())]);

    $this->artisan('bank-holidays:sync')->assertSuccessful();

    expect(BankHoliday::query()->count())->toBe(3)
        ->and(BankHoliday::query()->where('date', '2026-08-31')->value('title'))->toBe('Summer bank holiday');
});

it('ignores the other divisions', function () {
    Http::fake(['www.gov.uk/*' => Http::response(bankHolidayFeed())]);

    $this->artisan('bank-holidays:sync')->assertSuccessful();

    expect(BankHoliday::query()->where('date', '2026-11-30')->exists())->toBeFalse();
});

it('records substitute day notes', function () {
    Http::fake(['www.gov.uk/*' => Http::response(bankHolidayFeed())]);

    $this->artisan('bank-holidays:sync')->assertSuccessful();

    expect(BankHoliday::query()->where('date', '2026-12-28')->value('notes'))->toBe('Substitute day');
});

it('does not duplicate holidays when synced twice', function () {
    Http::fake(['www.gov.uk/*' => Http::response(bankHolidayFeed())]);

    $this->artisan('bank-holidays:sync')->assertSuccessful();
    $this->artisan('bank-holidays:sync')->assertSuccessful();

    expect(BankHoliday::query()->count())->toBe(3);
});

it('fails cleanly when the feed is unavailable', function () {
    Http::fake(['www.gov.uk/*' => Http::response('', 503)]);

    $this->artisan('bank-holidays:sync')->assertFailed();

    expect(BankHoliday::query()->count())->toBe(0);
});

it('fails cleanly when the division is missing', function () {
    Http::fake(['www.gov.uk/*' => Http::response(['scotland' => ['events' => []]])]);

    $this->artisan('bank-holidays:sync')->assertFailed();
});

it('expects no hours on a bank holiday', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '16:30']);
    }

    BankHoliday::factory()->on('2026-08-31')->create();

    $calculator = new FlexiCalculator;

    // 31 August 2026 is a Monday, so the pattern would otherwise expect 7.5 hours.
    expect($calculator->expectedHours(now()->parse('2026-08-31')))->toBe(0.0)
        ->and($calculator->expectedHours(now()->parse('2026-09-01')))->toBe(7.5);
});

it('leaves the balance untouched by an unworked bank holiday', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '16:30']);
    }

    BankHoliday::factory()->on('2026-08-31')->create();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-31')))->toBe(0.0);
})->note('Before this, a bank holiday read as a full day underworked.');

it('credits hours actually worked on a bank holiday', function () {
    $pattern = WorkPattern::factory()->create(['start_date' => '2026-01-01']);
    foreach (Weekday::cases() as $weekday) {
        $pattern->days()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '16:30']);
    }

    BankHoliday::factory()->on('2026-08-31')->create();
    WorkSession::factory()->on('2026-08-31', '09:00', '13:00')->create();

    expect((new FlexiCalculator)->deltaFor(now()->parse('2026-08-31')))->toBe(4.0);
});

it('shows the holiday on the timesheet', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-31 10:00');

    BankHoliday::factory()->on('2026-08-31')->create();

    Livewire::test('pages::timesheet')->assertSee('Summer bank holiday');
});

it('shows the holiday on the dashboard', function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-31 10:00');

    BankHoliday::factory()->on('2026-08-31')->create();

    Livewire::test('pages::dashboard')->assertSee('Summer bank holiday');
});

it('is scheduled to keep itself up to date', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'bank-holidays:sync'));

    expect($events)->not->toBeEmpty();
});
