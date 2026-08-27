<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    #[Validate('required|digits:6')]
    public string $pin = '';

    /**
     * Attempt to sign in with the submitted PIN.
     */
    public function authenticate(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        $user = User::query()->first();

        if ($user === null || ! Hash::check($this->pin, $user->pin)) {
            RateLimiter::hit($this->throttleKey());

            $this->reset('pin');

            throw ValidationException::withMessages([
                'pin' => __('That PIN is incorrect.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        Auth::login($user, remember: true);

        session()->regenerate();

        $this->redirectIntended(default: route('home'), navigate: true);
    }

    /**
     * Ensure the sign in request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'pin' => __('Too many attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    /**
     * Get the rate limiting key for the request.
     */
    protected function throttleKey(): string
    {
        return 'login:'.request()->ip();
    }
}; ?>

<div class="flex min-h-screen items-center justify-center p-6">
    <div class="w-full max-w-sm">
        <div class="flex flex-col items-center gap-2 text-center">
            <flux:icon name="lock-closed" class="size-8 text-zinc-400" />
            <flux:heading size="lg">{{ config('app.name') }}</flux:heading>
            <flux:text class="mb-2">Enter your PIN to continue</flux:text>
        </div>

        <form wire:submit="authenticate" class="mt-6 flex flex-col items-center gap-4">
            <flux:otp wire:model="pin" :length="6" private autofocus />

            <flux:error name="pin" />

            <flux:button type="submit" variant="primary" class="w-full">
                <span wire:loading.remove wire:target="authenticate">Sign in</span>
                <span wire:loading wire:target="authenticate">Signing in…</span>
            </flux:button>
        </form>
    </div>
</div>
