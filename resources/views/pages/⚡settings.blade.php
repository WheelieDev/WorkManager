<?php

use App\Models\Setting;
use App\Support\SicknessLedger;
use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component {
    public string $serviceStarted = '';

    public bool $saved = false;

    /**
     * What the next token will be called, so it can be told apart later.
     */
    public string $tokenName = '';

    /**
     * The token just created, in the clear. Only the hash is kept, so this is
     * the one chance to copy it.
     */
    public ?string $plainTextToken = null;

    /**
     * Start from whatever is in force, entered or configured.
     */
    public function mount(): void
    {
        $this->serviceStarted = (new SicknessLedger)->serviceStarted()?->toDateString() ?? '';
    }

    /**
     * Save the settings, or clear one back to its configured default.
     */
    public function save(): void
    {
        $this->validate([
            'serviceStarted' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'serviceStarted.before_or_equal' => 'Service cannot start in the future.',
        ]);

        Setting::write('service_started', $this->serviceStarted === ''
            ? null
            : Carbon::parse($this->serviceStarted)->toDateString());

        $this->saved = true;
    }

    /**
     * Create a token for another app, such as the menu bar, to use the API with.
     */
    public function createToken(): void
    {
        $this->validate([
            'tokenName' => ['required', 'string', 'max:255'],
        ], [
            'tokenName.required' => 'Say what the token is for.',
        ]);

        $this->plainTextToken = auth()->user()->createToken($this->tokenName)->plainTextToken;

        $this->reset('tokenName');
    }

    /**
     * Revoke a token, so whatever holds it is signed out.
     */
    public function revokeToken(int $id): void
    {
        auth()->user()->tokens()->whereKey($id)->delete();

        $this->plainTextToken = null;
    }

    /**
     * Show what the entered date means, so a typo is obvious.
     */
    public function with(): array
    {
        return [
            'sickness' => new SicknessLedger,
            'configured' => config('leave.service_started'),
            'tokens' => auth()->user()->tokens()->latest()->get(),
        ];
    }
}; ?>

<div class="mx-auto max-w-2xl">
    <div>
        <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">Settings</div>
        <h1 class="mt-1 text-2xl font-medium text-zinc-800 dark:text-white">Your details</h1>
    </div>

    <form wire:submit="save" class="mt-6 flex flex-col gap-5">
        <section class="rounded-xl border border-zinc-200 px-5 py-5 dark:border-zinc-700">
            <flux:field>
                <flux:label>Service started</flux:label>
                <flux:description>
                    The day your continuous service began. Sick pay is banded by how long it has run, so nothing can be
                    worked out without it.
                </flux:description>
                <flux:input type="date" wire:model="serviceStarted" max="{{ now()->toDateString() }}" />
                <flux:error name="serviceStarted" />
            </flux:field>

            @if ($configured)
                <flux:text variant="subtle" class="mt-3 text-xs">
                    Leave it blank to fall back to the configured {{ $configured }}.
                </flux:text>
            @endif

            {{-- What the date in force currently earns, so a wrong year shows itself. --}}
            @if ($sickness->isConfigured())
                <div class="mt-4 border-t border-zinc-100 pt-3 text-sm text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    You are in your {{ $sickness->yearOfServiceLabel() }},
                    earning {{ $sickness->band()->label() }}.
                </div>
            @endif
        </section>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Save</flux:button>

            @if ($saved)
                <flux:text variant="subtle" class="text-sm">Saved.</flux:text>
            @endif
        </div>
    </form>

    {{-- Tokens let another app, such as the menu bar, run the clock through
         the API. A token is shown once, when it is made: only its hash is kept.
         Each one is named so a lost laptop can be signed out on its own. --}}
    <section class="mt-5 rounded-xl border border-zinc-200 px-5 py-5 dark:border-zinc-700">
        <flux:heading>API tokens</flux:heading>
        <flux:text variant="subtle" class="mt-1">
            For the menu bar app and anything else that runs the clock from outside the browser.
            <flux:link :href="route('docs')">See the API documentation.</flux:link>
        </flux:text>

        <form wire:submit="createToken" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
            <flux:input
                wire:model="tokenName"
                placeholder="Work MacBook"
                :label:sr="'Token name'"
                class="sm:max-w-xs"
            />
            <flux:button type="submit">Create token</flux:button>
        </form>
        <flux:error name="tokenName" class="mt-1" />

        @if ($plainTextToken)
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-700 dark:bg-amber-950">
                <flux:text class="text-sm">Copy this now. It will not be shown again.</flux:text>
                <code class="mt-2 block break-all font-mono text-sm select-all">{{ $plainTextToken }}</code>
            </div>
        @endif

        @if ($tokens->isNotEmpty())
            <ul class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($tokens as $token)
                    <li class="flex items-center justify-between gap-4 py-2" wire:key="token-{{ $token->id }}">
                        <div class="min-w-0">
                            <div class="truncate text-sm text-zinc-800 dark:text-zinc-200">{{ $token->name }}</div>
                            <flux:text variant="subtle" class="text-xs">
                                Created {{ $token->created_at->diffForHumans() }}
                                @if ($token->last_used_at)
                                    &middot; last used {{ $token->last_used_at->diffForHumans() }}
                                @else
                                    &middot; not used yet
                                @endif
                            </flux:text>
                        </div>
                        <flux:button
                            size="sm"
                            variant="subtle"
                            wire:click="revokeToken({{ $token->id }})"
                            wire:confirm="Revoke this token? Whatever uses it will be signed out."
                        >Revoke</flux:button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Appearance is a preference of the browser rather than a detail of the
         job, so it is kept out of the form above and takes effect as it is
         chosen instead of waiting on a save. --}}
    <section class="mt-5 rounded-xl border border-zinc-200 px-5 py-5 dark:border-zinc-700">
        <flux:field>
            <flux:label>Appearance</flux:label>
            <flux:description>
                Following the system means the app turns dark when the rest of your machine does.
            </flux:description>

            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="mt-3">
                <flux:radio value="light" icon="sun" label="Light" />
                <flux:radio value="dark" icon="moon" label="Dark" />
                <flux:radio value="system" icon="computer-desktop" label="System" />
            </flux:radio.group>
        </flux:field>
    </section>
</div>
