<?php

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Support\FlexiCalculator;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public ?int $year = null;

    public ?int $editingId = null;

    public string $date = '';

    public string $hours = '';

    public bool $showingForm = false;

    /**
     * Start on the leave year we are in.
     */
    public function mount(): void
    {
        $this->year ??= LeaveYear::current()->year;
    }

    /**
     * Get the leave year on view.
     */
    public function leaveYear(): LeaveYear
    {
        return new LeaveYear($this->year ?? LeaveYear::current()->year);
    }

    /**
     * Step to an earlier or later leave year.
     */
    public function shiftYear(int $direction): void
    {
        $this->year = $this->leaveYear()->year + $direction;
    }

    /**
     * Return to the current leave year.
     */
    public function thisYear(): void
    {
        $this->year = LeaveYear::current()->year;
    }

    /**
     * Open the form to book leave, defaulting to a whole day of the pattern.
     */
    public function bookLeave(?string $date = null): void
    {
        $this->reset('editingId', 'hours');
        $this->resetValidation();

        $this->date = $date ?? now()->toDateString();
        $this->hours = (string) ((new FlexiCalculator)->patternHours(Carbon::parse($this->date)) ?: 0);
        $this->showingForm = true;
    }

    /**
     * Open the form to change a booking.
     */
    public function editLeave(LeaveDay $leave): void
    {
        $this->resetValidation();

        $this->editingId = $leave->id;
        $this->date = $leave->date->toDateString();
        $this->hours = (string) $leave->hours;
        $this->showingForm = true;
    }

    /**
     * Save the booking.
     */
    public function saveLeave(): void
    {
        $validated = $this->validate([
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
        ], [
            'hours.min' => 'Book at least a quarter of an hour.',
        ]);

        $attributes = ['hours' => round((float) $validated['hours'], 2)];

        if ($this->editingId !== null) {
            LeaveDay::query()->findOrFail($this->editingId)->update($attributes + ['date' => $validated['date']]);
        } else {
            LeaveDay::query()->updateOrCreate(
                ['date' => $validated['date'], 'type' => LeaveType::Annual],
                $attributes,
            );
        }

        $this->showingForm = false;
        $this->year = LeaveYear::forDate(Carbon::parse($validated['date']))->year;
    }

    /**
     * Cancel a booking.
     */
    public function deleteLeave(LeaveDay $leave): void
    {
        $leave->delete();
    }

    /**
     * Build the ledger for the year on view.
     */
    public function with(): array
    {
        $year = $this->leaveYear();
        $ledger = new LeaveLedger($year);

        return [
            'leaveYear' => $year,
            'ledger' => $ledger,
            'holidays' => $ledger->publicHolidays(),
            'bookings' => $ledger->booked(),
        ];
    }
}; ?>

@php
    $hours = fn (float $v): string => number_format($v, 2);
@endphp

<div class="mx-auto max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">Leave</div>
            <h1 class="mt-1 text-2xl font-medium text-zinc-800 dark:text-white">{{ $leaveYear->label() }}</h1>
        </div>

        <div class="flex items-center gap-1">
            <flux:button size="sm" variant="primary" icon="plus" wire:click="bookLeave">Book leave</flux:button>
            <flux:button icon="chevron-left" size="sm" variant="subtle" wire:click="shiftYear(-1)" aria-label="Previous leave year" />
            <flux:button size="sm" variant="subtle" wire:click="thisYear">This year</flux:button>
            <flux:button icon="chevron-right" size="sm" variant="subtle" wire:click="shiftYear(1)" aria-label="Next leave year" />
        </div>
    </div>

    {{-- One balance, with the public holiday adjustment shown as a line in it
         rather than as a second pot to reconcile by eye. --}}
    <section class="mt-6 rounded-xl border border-zinc-200 bg-ledger px-5 py-5 dark:border-zinc-700 dark:bg-white/[0.03]">
        <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">Bookable leave</div>

        <div class="mt-1 flex items-baseline gap-3">
            <span class="tabular text-4xl font-medium leading-none {{ $ledger->isOverdrawn() ? 'text-deficit dark:text-deficit-bright' : 'text-zinc-800 dark:text-white' }}">
                {{ $hours($ledger->annualRemaining()) }}
            </span>
            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                hours left of {{ $hours($ledger->annualEntitlement()) }}
                @if ($ledger->asDays($ledger->annualRemaining()) !== null)
                    &middot; about {{ $ledger->asDays($ledger->annualRemaining()) }} days
                @endif
            </span>
        </div>

        <dl class="mt-5 flex max-w-md flex-col gap-1 text-sm">
            <div class="flex justify-between">
                <dt class="text-zinc-500 dark:text-zinc-400">
                    {{ config('leave.annual_leave_days') }} days at your hours and weeks
                </dt>
                <dd class="tabular">{{ $hours($ledger->baseEntitlement()) }}</dd>
            </div>

            <div class="flex justify-between">
                <dt class="text-zinc-500 dark:text-zinc-400">
                    {{ $ledger->publicHolidayAdjustment() >= 0 ? 'Public holidays left over' : 'Public holidays over the allowance' }}
                </dt>
                <dd class="tabular {{ $ledger->publicHolidayAdjustment() < 0 ? 'text-deficit dark:text-deficit-bright' : 'text-surplus dark:text-surplus-bright' }}">
                    {{ $ledger->publicHolidayAdjustment() > 0 ? '+' : '' }}{{ $hours($ledger->publicHolidayAdjustment()) }}
                </dd>
            </div>

            <div class="flex justify-between ps-4 text-xs text-zinc-400 dark:text-zinc-500">
                <dt>
                    {{ config('leave.public_holiday_days') }} days at your hours
                    @if (config('leave.prorate_public_holidays')) and weeks @endif
                </dt>
                <dd class="tabular">{{ $hours($ledger->publicHolidayEntitlement()) }}</dd>
            </div>

            <div class="flex justify-between ps-4 text-xs text-zinc-400 dark:text-zinc-500">
                <dt>less taken by bank holidays</dt>
                <dd class="tabular">{{ $hours($ledger->publicHolidaysUsed()) }}</dd>
            </div>

            <div class="mt-1 flex justify-between border-t border-zinc-200 pt-1 font-medium dark:border-zinc-700">
                <dt class="text-zinc-500 dark:text-zinc-400">Entitlement</dt>
                <dd class="tabular">{{ $hours($ledger->annualEntitlement()) }}</dd>
            </div>

            <div class="flex justify-between">
                <dt class="text-zinc-500 dark:text-zinc-400">Booked</dt>
                <dd class="tabular">{{ $hours($ledger->annualBooked()) }}</dd>
            </div>
        </dl>
    </section>

    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
        You work <span class="tabular">{{ $hours($ledger->weeklyHours()) }}</span>h a week against a full time
        <span class="tabular">{{ $hours($ledger->fullTimeWeeklyHours()) }}</span>h, over
        <span class="tabular">{{ $ledger->contractedWeeks() }}</span> contracted weeks of
        <span class="tabular">{{ config('leave.weeks_per_year') }}</span>.
        @if ($ledger->publicHolidayAdjustment() > 0)
            Bank holidays miss your working days often enough to leave
            <span class="tabular">{{ $hours($ledger->publicHolidayAdjustment()) }}</span>h of the allowance
            unspent, which is added to your bookable leave.
        @elseif ($ledger->publicHolidayAdjustment() < 0)
            Bank holidays fall on your working days more often than the allowance covers, so
            <span class="tabular">{{ $hours($ledger->publicHolidayShortfall()) }}</span>h of bookable leave is
            making up the difference.
        @endif
    </p>

    {{-- Bank holidays, and what each one costs. --}}
    <section class="mt-8">
        <div class="mb-2 flex items-baseline justify-between border-b border-zinc-200 pb-1.5 text-[0.7rem] font-medium uppercase tracking-[0.16em] text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
            <span>Bank holidays</span>
            <span>Cost</span>
        </div>

        @forelse ($holidays as $entry)
            <div class="flex items-center justify-between gap-3 border-b border-zinc-100 py-1.5 text-sm dark:border-zinc-800"
                 wire:key="bh-{{ $entry['holiday']->id }}">
                <div class="flex items-baseline gap-3">
                    <span class="tabular w-28 shrink-0 text-zinc-400 dark:text-zinc-500">{{ $entry['holiday']->date->format('D j M Y') }}</span>
                    <span class="text-zinc-700 dark:text-zinc-200">{{ $entry['holiday']->title }}</span>
                </div>

                @if ($entry['hours'] > 0)
                    <span class="tabular text-zinc-700 dark:text-zinc-200">{{ $hours($entry['hours']) }}h</span>
                @else
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">not a working day</span>
                @endif
            </div>
        @empty
            <p class="py-2 text-sm text-zinc-400 dark:text-zinc-500">
                No bank holidays recorded. Run <code>php artisan bank-holidays:sync</code>.
            </p>
        @endforelse
    </section>

    {{-- Booked leave. --}}
    <section class="mt-8">
        <div class="mb-2 flex items-baseline justify-between border-b border-zinc-200 pb-1.5 text-[0.7rem] font-medium uppercase tracking-[0.16em] text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
            <span>Booked leave</span>
            <span>Hours</span>
        </div>

        @forelse ($bookings as $leave)
            <div class="group/leave flex items-center justify-between gap-3 border-b border-zinc-100 py-1.5 text-sm dark:border-zinc-800"
                 wire:key="leave-{{ $leave->id }}">
                <button type="button" class="flex items-baseline gap-3 text-start hover:underline" wire:click="editLeave({{ $leave->id }})">
                    <span class="tabular w-28 shrink-0 text-zinc-400 dark:text-zinc-500">{{ $leave->date->format('D j M Y') }}</span>
                    <span class="text-zinc-700 dark:text-zinc-200">{{ $leave->type->label() }}</span>
                </button>

                <div class="flex items-center gap-3">
                    <span class="tabular text-zinc-700 dark:text-zinc-200">{{ $hours($leave->hours) }}h</span>
                    <button type="button"
                            class="text-zinc-300 opacity-0 transition hover:text-deficit focus-visible:opacity-100 group-hover/leave:opacity-100 dark:text-zinc-600"
                            wire:click="deleteLeave({{ $leave->id }})"
                            wire:confirm="Cancel this leave?"
                            aria-label="Cancel leave">&times;</button>
                </div>
            </div>
        @empty
            <p class="py-2 text-sm text-zinc-400 dark:text-zinc-500">No leave booked this year.</p>
        @endforelse
    </section>

    <flux:modal wire:model="showingForm" class="max-w-sm">
        <form wire:submit="saveLeave" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Change leave' : 'Book leave' }}</flux:heading>

            <flux:field>
                <flux:label>Date</flux:label>
                <flux:input type="date" wire:model="date" />
                <flux:error name="date" />
            </flux:field>

            <flux:field>
                <flux:label>Hours</flux:label>
                <flux:description>A whole day is whatever your pattern works that day.</flux:description>
                <flux:input type="number" step="0.25" min="0.25" wire:model="hours" />
                <flux:error name="hours" />
            </flux:field>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Save</flux:button>
                <flux:button variant="ghost" wire:click="$set('showingForm', false)">Cancel</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
