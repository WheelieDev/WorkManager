<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->user = User::factory()->create(['pin' => '123456']);
});

it('exchanges the PIN for a named token', function () {
    $response = $this->postJson('/api/auth/tokens', ['pin' => '123456', 'device_name' => 'Menu bar'])
        ->assertCreated()
        ->assertJsonStructure(['token']);

    expect($this->user->tokens()->sole()->name)->toBe('Menu bar');

    $this->withToken($response->json('token'))->getJson('/api/timer')->assertOk();
});

it('returns 422 for a wrong PIN and counts the attempt', function () {
    $this->postJson('/api/auth/tokens', ['pin' => '654321', 'device_name' => 'Menu bar'])
        ->assertJsonValidationErrors(['pin' => 'That PIN is incorrect.']);

    expect($this->user->tokens()->count())->toBe(0)
        ->and(RateLimiter::attempts('login:127.0.0.1'))->toBe(1);
});

it('returns 422 when the PIN or device name is missing', function () {
    $this->postJson('/api/auth/tokens')->assertJsonValidationErrors(['pin', 'device_name']);
});

it('refuses after five wrong PINs, sharing the login page bucket', function () {
    foreach (range(1, 5) as $attempt) {
        RateLimiter::hit('login:127.0.0.1');
    }

    $this->postJson('/api/auth/tokens', ['pin' => '123456', 'device_name' => 'Menu bar'])
        ->assertJsonValidationErrors('pin')
        ->assertJsonPath('errors.pin.0', fn (string $message) => str_starts_with($message, 'Too many attempts.'));

    expect($this->user->tokens()->count())->toBe(0);
})->note('A wrong PIN over the API is a wrong PIN; the API is not a second set of guesses.');

it('clears the attempt count once the PIN is right', function () {
    RateLimiter::hit('login:127.0.0.1');

    $this->postJson('/api/auth/tokens', ['pin' => '123456', 'device_name' => 'Menu bar'])->assertCreated();

    expect(RateLimiter::attempts('login:127.0.0.1'))->toBe(0);
});

it('revokes the token it was called with', function () {
    $keep = $this->user->createToken('Laptop')->plainTextToken;
    $revoke = $this->user->createToken('Menu bar')->plainTextToken;

    $this->withToken($revoke)->deleteJson('/api/auth/tokens/current')->assertNoContent();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['Laptop']);

    Auth::forgetGuards();

    $this->withToken($revoke)->getJson('/api/timer')->assertUnauthorized();
    $this->withToken($keep)->getJson('/api/timer')->assertOk();
});

it('returns 401 when revoking without a token', function () {
    $this->deleteJson('/api/auth/tokens/current')->assertUnauthorized();
});
