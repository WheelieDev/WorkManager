<?php

use App\Models\User;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Database\Seeders\SandboxSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Point the marker somewhere disposable so switching in a test cannot
    // change which database the developer's own machine is using.
    $this->marker = storage_path('framework/testing/using-sandbox-database');

    File::ensureDirectoryExists(dirname($this->marker));
    File::delete($this->marker);

    config(['database.sandbox_marker' => $this->marker]);
});

afterEach(function () {
    File::delete($this->marker);
});

it('reports the main database by default', function () {
    $this->artisan('db:use')
        ->expectsOutputToContain('Using the main database')
        ->assertSuccessful();
});

it('switches to the sandbox', function () {
    $this->artisan('db:use', ['target' => 'sandbox'])
        ->expectsOutputToContain('Using the SANDBOX database')
        ->assertSuccessful();

    expect(File::exists($this->marker))->toBeTrue();
});

it('switches back to the main database', function () {
    $this->artisan('db:use', ['target' => 'sandbox'])->assertSuccessful();
    $this->artisan('db:use', ['target' => 'main'])
        ->expectsOutputToContain('Using the main database')
        ->assertSuccessful();

    expect(File::exists($this->marker))->toBeFalse();
});

it('warns while the sandbox is in use', function () {
    $this->artisan('db:use', ['target' => 'sandbox'])
        ->expectsOutputToContain('Sandbox data is disposable')
        ->assertSuccessful();
});

it('rejects an unknown database', function () {
    $this->artisan('db:use', ['target' => 'production'])
        ->expectsOutputToContain('Unknown database [production]')
        ->assertFailed();

    expect(File::exists($this->marker))->toBeFalse();
});

it('refuses to rebuild the main database', function () {
    $this->artisan('db:use', ['target' => 'main', '--fresh' => true])
        ->expectsOutputToContain('Only the sandbox may be rebuilt')
        ->assertFailed();
})->note('--fresh drops every table, so it must never point at real hours.');

it('leaves the marker out of the repository', function () {
    expect(trim(File::get(base_path('database/.gitignore'))))->toContain('*.sqlite*')
        ->and(File::get(storage_path('app/.gitignore')))->toContain('*');
});

it('gives the sandbox its own account and a period to look at', function () {
    $this->seed(SandboxSeeder::class);

    expect(User::query()->sole()->name)->toBe('Sandbox')
        ->and(Hash::check(SandboxSeeder::Pin, User::query()->sole()->pin))->toBeTrue()
        ->and(WorkPattern::query()->count())->toBe(1)
        ->and(WorkSession::query()->count())->toBeGreaterThan(0);
});

it('seeds days both over and under the pattern', function () {
    // After tracking has begun, so the seeded days actually count.
    $this->travelTo('2026-09-16');
    $this->seed(SandboxSeeder::class);

    $calculator = new FlexiCalculator;
    $deltas = FlexiPeriod::current()->dates()
        ->reject(fn ($date) => $date->startOfDay()->isFuture())
        ->map(fn ($date) => $calculator->deltaFor($date));

    expect($deltas->contains(fn (float $delta): bool => $delta > 0))->toBeTrue()
        ->and($deltas->contains(fn (float $delta): bool => $delta < 0))->toBeTrue();
})->note('A flat ledger would not exercise the bars either side of the line.');

it('names a sandbox database alongside the real one on mysql', function () {
    expect(config('database.sandbox_database'))->toBe(config('database.main_database').'_sandbox');
})->note('The sandbox must never resolve to the same database as the real hours.');

it('refuses to rebuild unless the connection really moved to the sandbox', function () {
    // The marker says sandbox, but the connection is the in-memory database,
    // which is never repointed. That mismatch is how --fresh could otherwise
    // reach a database holding real hours.
    $this->artisan('db:use', ['target' => 'sandbox', '--fresh' => true])
        ->expectsOutputToContain('Refusing to rebuild')
        ->assertFailed();
})->note('--fresh drops every table it can reach, so the guard is the last line of defence.');
