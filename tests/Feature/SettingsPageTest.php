<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\SicknessLedger;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-29 10:00');

    partTimePattern();
});

it('is reachable and needs signing in', function () {
    $this->get('/settings')->assertOk();

    auth()->logout();

    $this->get('/settings')->assertRedirect(route('login'));
});

it('opens on the date already in force', function () {
    config(['leave.service_started' => '2020-01-01']);

    Livewire::test('pages::settings')->assertSet('serviceStarted', '2020-01-01');
});

it('saves the service start date', function () {
    Livewire::test('pages::settings')
        ->set('serviceStarted', '2019-05-06')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::read('service_started'))->toBe('2019-05-06')
        ->and((new SicknessLedger)->serviceStarted()->toDateString())->toBe('2019-05-06');
});

it('lets an entered date override the configured one', function () {
    config(['leave.service_started' => '2020-01-01']);

    Livewire::test('pages::settings')
        ->set('serviceStarted', '2015-09-01')
        ->call('save')
        ->assertHasNoErrors();

    expect((new SicknessLedger)->serviceStarted()->toDateString())->toBe('2015-09-01');
})->note('Config is the default that ships; what the user enters is a decision.');

it('clears back to the configured date when emptied', function () {
    config(['leave.service_started' => '2020-01-01']);
    Setting::write('service_started', '2015-09-01');

    Livewire::test('pages::settings')
        ->set('serviceStarted', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'service_started')->exists())->toBeFalse()
        ->and((new SicknessLedger)->serviceStarted()->toDateString())->toBe('2020-01-01');
});

it('refuses a service start in the future', function () {
    Livewire::test('pages::settings')
        ->set('serviceStarted', '2027-01-01')
        ->call('save')
        ->assertHasErrors('serviceStarted');

    expect(Setting::query()->count())->toBe(0);
});

it('shows what the date in force earns', function () {
    Livewire::test('pages::settings')
        ->set('serviceStarted', '2019-05-06')
        ->call('save')
        ->assertSee('your 8th year of service')
        ->assertSee('6 months full pay and 6 months half pay');
})->note('A mistyped year is easiest to catch by what it claims to earn.');

it('feeds the sickness allowance on the leave page', function () {
    config(['leave.service_started' => null]);

    Livewire::test('pages::leave')->assertSee('service_started');

    Livewire::test('pages::settings')
        ->set('serviceStarted', '2024-01-01')
        ->call('save');

    Livewire::test('pages::leave')
        ->assertSee('Full pay')
        ->assertDontSee('service_started');
});
