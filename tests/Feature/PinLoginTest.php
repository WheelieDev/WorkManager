<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

it('redirects guests to the login screen', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('signs in with the correct pin', function () {
    $user = User::factory()->withPin('482913')->create();

    Livewire::test('pages::login')
        ->set('pin', '482913')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('rejects an incorrect pin and clears the field', function () {
    User::factory()->withPin('482913')->create();

    Livewire::test('pages::login')
        ->set('pin', '000000')
        ->call('authenticate')
        ->assertHasErrors('pin')
        ->assertSet('pin', '');

    expect(auth()->check())->toBeFalse();
});

it('requires exactly six digits', function () {
    User::factory()->withPin('482913')->create();

    Livewire::test('pages::login')
        ->set('pin', '4829')
        ->call('authenticate')
        ->assertHasErrors(['pin' => 'digits']);

    expect(auth()->check())->toBeFalse();
});

it('throttles after five failed attempts', function () {
    User::factory()->withPin('482913')->create();

    $component = Livewire::test('pages::login');

    foreach (range(1, 5) as $attempt) {
        $component->set('pin', '000000')->call('authenticate')->assertHasErrors('pin');
    }

    $component->set('pin', '482913')->call('authenticate')->assertHasErrors('pin');

    expect(auth()->check())->toBeFalse();
    expect(RateLimiter::tooManyAttempts('login:127.0.0.1', 5))->toBeTrue();
});

it('lets a signed in user log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('keeps signed in users away from the login screen', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

it('renders the dashboard with the sidebar for a signed in user', function () {
    $this->actingAs(User::factory()->create(['name' => 'Tom']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Flexi balance')
        ->assertSee('data-flux-sidebar', escape: false);
});

it('does not render the sidebar for guests', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-flux-sidebar', escape: false);
});
