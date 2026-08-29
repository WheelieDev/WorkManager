<?php

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Models\LeaveCarryover;
use App\Models\LeaveRequest;
use App\Support\FlexiCalculator;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public ?int $year = null;

    public ?int $editingId = null;

    public string $from = '';

    public string $to = '';

    public string $hours = '';

    public string $type = LeaveType::Annual->value;

    public bool $showingForm = false;

    public string $carryover = '';

    public bool $showingCarryover = false;

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
     * Open the form to book leave.
     */
    public function bookLeave(?string $date = null): void
    {
        $this->reset('editingId', 'hours', 'type');
        $this->resetValidation();

        $this->from = $date ?? now()->toDateString();
        $this->to = $this->from;
        $this->showingForm = true;
    }

    /**
     * Keep the end of the range from falling behind the start.
     */
    public function updatedFrom(string $value): void
    {
        if ($this->to === '' || $this->to < $value) {
            $this->to = $value;
        }
    }

    /**
     * Open the form to change a request.
     */
    public function editLeave(LeaveRequest $request): void
    {
        $this->resetValidation();

        $this->editingId = $request->id;
        $this->from = $request->starts_on->toDateString();
        $this->to = $request->ends_on->toDateString();
        $this->hours = $request->isSingleDay() ? (string) ($request->days->first()?->hours ?? '') : '';
        $this->type = ($request->days->first()?->type ?? LeaveType::Annual)->value;
        $this->showingForm = true;
    }

    /**
     * Get the days in the chosen range that are worth booking, with the hours
     * the pattern asks for on each.
     *
     * Days the pattern does not work are skipped, and so are bank holidays,
     * which are already paid for.
     *
     * @return Collection<int, array{date: Carbon, hours: float}>
     */
    public function daysInRange(): Collection
    {
        if ($this->from === '') {
            return collect();
        }

        $from = Carbon::parse($this->from)->startOfDay();
        $to = Carbon::parse($this->to ?: $this->from)->startOfDay();

        if ($to->lessThan($from) || $from->diffInDays($to) > 366) {
            return collect();
        }

        $calculator = new FlexiCalculator;
        $days = collect();

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $hours = $calculator->isBankHoliday($date) ? 0.0 : $calculator->patternHours($date);

            if ($hours > 0) {
                $days->push(['date' => $date->copy(), 'hours' => $hours]);
            }
        }

        return $days;
    }

    /**
     * Save the request and the days it books.
     */
    public function saveLeave(): void
    {
        $this->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'hours' => ['nullable', 'numeric', 'min:0.25', 'max:24'],
            'type' => ['required', Rule::enum(LeaveType::class)],
        ], [
            'to.after_or_equal' => 'The last day cannot be before the first.',
            'hours.min' => 'Book at least a quarter of an hour.',
        ]);

        $days = $this->daysInRange();

        if ($days->isEmpty()) {
            $this->addError('from', 'You do not work any of those days, so there is nothing to book.');

            return;
        }

        // A single day may be taken in part; a range is taken whole.
        $partDay = $days->count() === 1 && $this->hours !== '' ? round((float) $this->hours, 2) : null;

        $type = LeaveType::from($this->type);

        $request = $this->editingId !== null
            ? LeaveRequest::query()->findOrFail($this->editingId)
            : new LeaveRequest;

        $request->starts_on = $this->from;
        $request->ends_on = $this->to ?: $this->from;
        $request->save();

        $request->days()->delete();

        // A day holds one entry per type, so rebooking moves that type across
        // and leaves any other absence on the day untouched.
        LeaveDay::query()
            ->where('type', $type)
            ->whereIn('date', $days->map(fn (array $day): string => $day['date']->toDateString()))
            ->delete();

        foreach ($days as $day) {
            $request->days()->create([
                'date' => $day['date']->toDateString(),
                'type' => $type,
                'hours' => $partDay !== null ? min($partDay, $day['hours']) : $day['hours'],
            ]);
        }

        // Requests that lost every day to this one are no longer requests.
        LeaveRequest::query()->whereDoesntHave('days')->delete();

        $this->showingForm = false;
        $this->year = LeaveYear::forDate($days->first()['date'])->year;
    }

    /**
     * Open the form to set what is carried into this year.
     */
    public function editCarryover(): void
    {
        $this->resetValidation();

        $ledger = new LeaveLedger($this->leaveYear());

        $this->carryover = $ledger->carryWasEntered() ? (string) $ledger->carriedIn() : '';
        $this->showingCarryover = true;
    }

    /**
     * Save the carried figure, or clear it back to the calculation.
     */
    public function saveCarryover(): void
    {
        $this->validate([
            'carryover' => ['nullable', 'numeric', 'min:-500', 'max:500'],
        ]);

        $year = $this->leaveYear()->year;

        if ($this->carryover === '') {
            LeaveCarryover::query()->where('leave_year', $year)->delete();
        } else {
            LeaveCarryover::query()->updateOrCreate(
                ['leave_year' => $year],
                ['hours' => round((float) $this->carryover, 2)],
            );
        }

        $this->showingCarryover = false;
    }

    /**
     * Cancel a request and everything it booked.
     */
    public function deleteLeave(LeaveRequest $request): void
    {
        $request->delete();
    }

    /**
     * Build the ledger for the year on view.
     */
    public function with(): array
    {
        $year = $this->leaveYear();
        $ledger = new LeaveLedger($year);

        $days = $this->showingForm ? $this->daysInRange() : collect();

        return [
            'chosenDays' => $days,
            'chosenHours' => round($days->sum('hours'), 2),
            'leaveYear' => $year,
            'ledger' => $ledger,
            'requests' => $ledger->requests(),
            'otherLeave' => $ledger->otherLeave(),
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
            <div class="flex items-baseline justify-between gap-2">
                <dt class="flex items-baseline gap-2 text-zinc-500 dark:text-zinc-400">
                    Carried in from {{ $leaveYear->previous()->start->format('Y') }}/{{ $leaveYear->previous()->end->format('y') }}
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">
                        {{ $ledger->carryWasEntered() ? 'entered' : 'calculated' }}
                    </span>
                    <flux:button
                        icon="pencil-square"
                        size="xs"
                        variant="subtle"
                        inset="top bottom"
                        wire:click="editCarryover"
                        aria-label="Change what is carried in"
                    />
                </dt>
                <dd class="tabular {{ $ledger->carriedIn() < 0 ? 'text-deficit dark:text-deficit-bright' : '' }}">
                    {{ $ledger->carriedIn() > 0 ? '+' : '' }}{{ $hours($ledger->carriedIn()) }}
                </dd>
            </div>

            <div class="flex justify-between">
                <dt class="text-zinc-500 dark:text-zinc-400">
                    @if ($ledger->isAwarded())
                        Annual leave, as awarded
                    @else
                        {{ config('leave.annual_leave_days') }} days at your hours and weeks
                    @endif
                </dt>
                <dd class="tabular">{{ $hours($ledger->baseEntitlement()) }}</dd>
            </div>

            @unless ($ledger->isAwarded())
                <div class="flex justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">
                        {{ $ledger->publicHolidayAdjustment() >= 0 ? 'Public holidays left over' : 'Public holidays over the allowance' }}
                    </dt>
                    <dd class="tabular {{ $ledger->publicHolidayAdjustment() < 0 ? 'text-deficit dark:text-deficit-bright' : 'text-surplus dark:text-surplus-bright' }}">
                        {{ $ledger->publicHolidayAdjustment() > 0 ? '+' : '' }}{{ $hours($ledger->publicHolidayAdjustment()) }}
                    </dd>
                </div>
            @endunless

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

            @if ($ledger->isAwarded())
                <div class="flex justify-between ps-4 text-xs text-zinc-400 dark:text-zinc-500">
                    <dt>&mdash; already inside the awarded figure</dt>
                    <dd class="tabular">{{ $ledger->publicHolidayAdjustment() > 0 ? '+' : '' }}{{ $hours($ledger->publicHolidayAdjustment()) }}</dd>
                </div>
            @endif

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
        @if ($ledger->isAwarded())
            Your awarded figure already settles public holidays: an allowance of
            <span class="tabular">{{ $hours($ledger->publicHolidayEntitlement()) }}</span>h against
            <span class="tabular">{{ $hours($ledger->publicHolidaysUsed()) }}</span>h taken by bank holidays.
        @elseif ($ledger->publicHolidayAdjustment() > 0)
            Bank holidays miss your working days often enough to leave
            <span class="tabular">{{ $hours($ledger->publicHolidayAdjustment()) }}</span>h of the allowance
            unspent, which is added to your bookable leave.
        @elseif ($ledger->publicHolidayAdjustment() < 0)
            Bank holidays fall on your working days more often than the allowance covers, so
            <span class="tabular">{{ $hours($ledger->publicHolidayShortfall()) }}</span>h of bookable leave is
            making up the difference.
        @endif
    </p>

    {{-- Absence granted apart from annual leave. Each type stands on its own,
         so nothing here has cost a holiday. --}}
    @if ($otherLeave->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                Other leave
            </h2>

            <dl class="mt-3 flex flex-col gap-1 text-sm">
                @foreach ($otherLeave as $other)
                    <div class="flex items-baseline justify-between gap-2 border-b border-zinc-100 py-1.5 last:border-0 dark:border-zinc-800"
                         wire:key="other-{{ $other->type->value }}">
                        <dt class="text-zinc-600 dark:text-zinc-300">{{ $other->type->label() }}</dt>

                        <dd class="tabular text-zinc-500 dark:text-zinc-400">
                            @if ($other->isCapped())
                                <span class="font-medium {{ $other->isOverdrawn() ? 'text-deficit dark:text-deficit-bright' : 'text-zinc-800 dark:text-white' }}">
                                    {{ $hours($other->remaining()) }}
                                </span>
                                h left of {{ $hours($other->entitlement) }}
                            @else
                                {{ $hours($other->used) }}h taken
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    {{-- Booked leave, as it was asked for. --}}
    <section class="mt-8">
        <div class="flex items-baseline justify-between">
            <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                Booked leave
            </h2>

            @if ($requests->isNotEmpty())
                <flux:text variant="subtle" class="text-sm">
                    {{ $requests->count() }} {{ Str::plural('request', $requests->count()) }}
                </flux:text>
            @endif
        </div>

        @if ($requests->isEmpty())
            <p class="mt-2 border-t border-zinc-200 pt-3 text-sm text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
                No leave booked this year. Choose a first and last day and the hours come from your pattern.
            </p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-[0.7rem] uppercase tracking-[0.12em] text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
                            <th class="py-1.5 text-start font-medium">Dates</th>
                            <th class="py-1.5 text-start font-medium">Type</th>
                            <th class="py-1.5 text-end font-medium">Days</th>
                            <th class="py-1.5 text-end font-medium">Bank holidays</th>
                            <th class="py-1.5 text-end font-medium">Hours</th>
                            <th class="w-20"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($requests as $request)
                            @php
                                $holidays = $ledger->requestPublicHolidays($request);
                                $requestType = $request->days->first()?->type;
                            @endphp

                            <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="request-{{ $request->id }}">
                                <td class="tabular py-1.5 text-zinc-700 dark:text-zinc-200">
                                    {{ $request->label() }}
                                </td>

                                <td class="py-1.5 text-zinc-500 dark:text-zinc-400">
                                    {{ $requestType?->label() ?? '—' }}
                                </td>

                                <td class="tabular py-1.5 text-end text-zinc-500 dark:text-zinc-400">
                                    {{ $ledger->requestDays($request) }}
                                </td>

                                <td class="tabular py-1.5 text-end {{ $holidays > 0 ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-300 dark:text-zinc-600' }}">
                                    {{ $holidays > 0 ? $holidays : '—' }}
                                </td>

                                <td class="tabular py-1.5 text-end text-zinc-700 dark:text-zinc-200">
                                    {{ $hours($ledger->requestHours($request)) }}
                                </td>

                                <td class="py-1.5 text-end">
                                    <div class="flex justify-end gap-1">
                                        <flux:button
                                            icon="pencil-square"
                                            size="xs"
                                            variant="subtle"
                                            inset="top bottom"
                                            wire:click="editLeave({{ $request->id }})"
                                            :aria-label="'Edit leave from '.$request->starts_on->format('j F Y')"
                                        />

                                        <flux:button
                                            icon="trash"
                                            size="xs"
                                            variant="subtle"
                                            inset="top bottom"
                                            wire:click="deleteLeave({{ $request->id }})"
                                            wire:confirm="Cancel this leave? The {{ $ledger->requestDays($request) }} {{ Str::plural('day', $ledger->requestDays($request)) }} it booked will be given back."
                                            :aria-label="'Cancel leave from '.$request->starts_on->format('j F Y')"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr class="font-medium">
                            <td class="py-1.5">Total</td>
                            <td class="tabular py-1.5 text-end">{{ $requests->sum(fn ($r) => $ledger->requestDays($r)) }}</td>
                            <td class="tabular py-1.5 text-end">{{ $requests->sum(fn ($r) => $ledger->requestPublicHolidays($r)) ?: '—' }}</td>
                            <td class="tabular py-1.5 text-end">{{ $hours($ledger->annualBooked()) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>

    <flux:modal wire:model="showingForm" class="max-w-md">
        <form wire:submit="saveLeave" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Change leave' : 'Book leave' }}</flux:heading>

            <flux:field>
                <flux:label>Type</flux:label>
                <flux:select wire:model.live="type">
                    @foreach (\App\Enums\LeaveType::bookable() as $leaveType)
                        <flux:select.option value="{{ $leaveType->value }}">{{ $leaveType->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>
                    Only annual leave comes off your bookable balance.
                </flux:description>
                <flux:error name="type" />
            </flux:field>

            <div class="flex items-end gap-3">
                <flux:field>
                    <flux:label>{{ $editingId ? 'Date' : 'From' }}</flux:label>
                    <flux:input type="date" wire:model.live="from" />
                </flux:field>

                @unless ($editingId)
                    <flux:field>
                        <flux:label>To</flux:label>
                        <flux:input type="date" wire:model.live="to" />
                    </flux:field>
                @endunless
            </div>

            <flux:error name="from" />
            <flux:error name="to" />

            {{-- What the pattern says those dates are worth. --}}
            <div class="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                @if ($chosenDays->isEmpty())
                    <flux:text variant="subtle" class="text-sm">
                        You do not work any of those days.
                    </flux:text>
                @else
                    <div class="flex items-baseline justify-between">
                        <flux:text variant="strong" class="text-sm">
                            {{ $chosenDays->count() }} {{ Str::plural('day', $chosenDays->count()) }}
                        </flux:text>
                        <span class="tabular text-sm font-medium">{{ $hours($chosenHours) }}h</span>
                    </div>

                    <div class="mt-1 flex max-h-32 flex-col gap-0.5 overflow-y-auto">
                        @foreach ($chosenDays as $day)
                            <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400"
                                 wire:key="chosen-{{ $day['date']->toDateString() }}">
                                <span>{{ $day['date']->format('D j M') }}</span>
                                <span class="tabular">{{ $hours($day['hours']) }}h</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Part days only make sense for one day at a time. --}}
            @if ($chosenDays->count() === 1)
                <flux:field>
                    <flux:label>Hours</flux:label>
                    <flux:description>Leave blank for the whole day.</flux:description>
                    <flux:input type="number" step="0.25" min="0.25" wire:model="hours"
                                placeholder="{{ $hours($chosenHours) }}" />
                    <flux:error name="hours" />
                </flux:field>
            @endif

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" :disabled="$chosenDays->isEmpty()">Save</flux:button>
                <flux:button variant="ghost" wire:click="$set('showingForm', false)">Cancel</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showingCarryover" class="max-w-sm">
        <form wire:submit="saveCarryover" class="flex flex-col gap-4">
            <flux:heading size="lg">Carried in</flux:heading>

            <flux:field>
                <flux:label>Hours brought forward</flux:label>
                <flux:description>
                    Leave blank to work it out from {{ $leaveYear->previous()->label() }}, where there is
                    anything to go on.
                </flux:description>
                <flux:input type="number" step="0.25" wire:model="carryover"
                            placeholder="{{ $hours($ledger->carriedIn()) }}" />
                <flux:error name="carryover" />
            </flux:field>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Save</flux:button>
                <flux:button variant="ghost" wire:click="$set('showingCarryover', false)">Cancel</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
