<?php

use App\Models\Setting;
use App\Support\SicknessLedger;
use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component {
    public string $serviceStarted = '';

    public bool $saved = false;

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
     * Show what the entered date means, so a typo is obvious.
     */
    public function with(): array
    {
        return [
            'sickness' => new SicknessLedger,
            'configured' => config('leave.service_started'),
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
                    {{ $sickness->yearsOfService() }} {{ Str::plural('year', $sickness->yearsOfService()) }} of service,
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
</div>
