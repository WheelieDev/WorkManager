<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the account when none exists yet', function () {
    $this->artisan('pin:set', ['pin' => '482913'])->assertSuccessful();

    $user = User::query()->sole();

    expect(Hash::check('482913', $user->pin))->toBeTrue();
});

it('updates the pin for the existing account', function () {
    $user = User::factory()->withPin('111111')->create();

    $this->artisan('pin:set', ['pin' => '482913'])->assertSuccessful();

    expect(Hash::check('482913', $user->fresh()->pin))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('rejects a pin that is not six digits', function () {
    $this->artisan('pin:set', ['pin' => '12ab'])->assertFailed();

    expect(User::query()->count())->toBe(0);
});
