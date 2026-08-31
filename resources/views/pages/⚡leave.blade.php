<?php

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Models\LeaveCarryover;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Support\FlexiCalculator;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;
use App\Support\LieuLedger;
use App\Support\SicknessLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Url]
    public ?int $year = null;

    #[Url]
    public string $tab = 'annual';

    public ?int $editingId = null;

    public string $from = '';

    public string $to = '';

    public string $hours = '';

    public string $type = LeaveType::Annual->value;

    public bool $showingForm = false;

    public string $carryover = '';

    public bool $showingCarryover = false;

    public string $opening = '';

    public bool $showingOpening = false;

    /**
     * Start on the leave year we are in.
     */
    public function mount(): void
    {
        $this->year ??= LeaveYear::current()->year;
    }

    /**
     * Get the pots, and what each is called.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            'annual' => 'Annual leave',
            'lieu' => 'Time off in lieu',
            'other' => 'Other leave',
            'sickness' => 'Sickness',
        ];
    }

    /**
     * Open a pot, or close the one already open.
     *
     * One at a time: two sets of working out side by side invite the reading
     * that they add up, and these pots never trade with each other.
     */
    public function togglePot(string $key): void
    {
        $this->tab = $this->tab === $key ? '' : $key;
    }

    /**
     * Get the leave types the pot on view is responsible for.
     *
     * @return array<int, LeaveType>
     */
    public function tabTypes(): array
    {
        return match ($this->tab) {
            'lieu' => [LeaveType::Lieu],
            'other' => LeaveType::separate(),
            'sickness' => [LeaveType::Sickness],
            default => [LeaveType::Annual],
        };
    }

    /**
     * Get the sickness ledger, as it stands today.
     *
     * Sick pay runs over the twelve months before an absence begins, so the
     * figures are anchored to a day. Today is the day worth showing: it says
     * what is being counted right now.
     */
    public function sicknessLedger(): SicknessLedger
    {
        return new SicknessLedger;
    }

    /**
     * Get the time off in lieu ledger, which runs across every year.
     */
    public function lieuLedger(): LieuLedger
    {
        return new LieuLedger;
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
        $this->reset('editingId', 'hours');
        $this->resetValidation();

        // Booking from the sickness tab and getting annual leave would be a trap.
        $this->type = ($this->tabTypes()[0] ?? LeaveType::Annual)->value;

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

        // Lieu is spent from a balance that has to have been earned first, so
        // a booking bigger than what is banked is refused rather than owed.
        if ($type->drawsOnLieu() && ! $this->lieuCovers($days, $partDay)) {
            return;
        }

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
     * Check the lieu balance stretches to the booking, and say so if it does not.
     *
     * The lieu this booking replaces is credited back first, so shortening a
     * booking or moving it a day is never refused for hours it is giving up.
     *
     * @param  Collection<int, array{date: Carbon, hours: float}>  $days
     */
    protected function lieuCovers(Collection $days, ?float $partDay): bool
    {
        $lieu = $this->lieuLedger();

        $wanted = round($days->sum(fn (array $day): float => $partDay !== null
            ? min($partDay, $day['hours'])
            : $day['hours']), 2);

        $dates = $days->map(fn (array $day): string => $day['date']->toDateString());

        $replaced = LeaveDay::query()
            ->where('type', LeaveType::Lieu)
            ->where(function (Builder $query) use ($dates): void {
                $query->whereIn('date', $dates);

                if ($this->editingId !== null) {
                    $query->orWhere('leave_request_id', $this->editingId);
                }
            })
            ->get();

        $credit = round($replaced->sum(fn (LeaveDay $day): float => $lieu->effectiveHours($day)), 2);

        if ($lieu->canAfford($wanted, $credit)) {
            return true;
        }

        $this->addError('from', 'That would take '.number_format($wanted, 2).'h of lieu, and you have only '
            .number_format($lieu->balance() + $credit, 2).'h banked. Track some time in lieu first.');

        return false;
    }

    /**
     * Open the form to set the lieu balance tracking started from.
     */
    public function editOpening(): void
    {
        $this->resetValidation();

        $lieu = $this->lieuLedger();

        $this->opening = $lieu->openingWasEntered() ? (string) $lieu->openingBalance() : '';
        $this->showingOpening = true;
    }

    /**
     * Save the opening lieu balance, or clear it back to the configured one.
     */
    public function saveOpening(): void
    {
        $this->validate([
            'opening' => ['nullable', 'numeric', 'min:0', 'max:500'],
        ], [
            'opening.min' => 'An opening balance cannot be negative.',
        ]);

        $lieu = $this->lieuLedger();
        $opening = $this->opening === '' ? (float) config('leave.lieu_opening_hours', 0) : round((float) $this->opening, 2);

        // Lowering the opening figure below the lieu already taken would leave
        // the pot overdrawn, which is the one thing it must never be.
        if ($opening + $lieu->earned() - $lieu->booked() < 0) {
            $this->addError('opening', 'You have already taken '.number_format($lieu->booked(), 2)
                .'h of lieu against '.number_format($lieu->earned(), 2).'h worked, so the balance cannot open below '
                .number_format(max(0, $lieu->booked() - $lieu->earned()), 2).'h.');

            return;
        }

        Setting::write('lieu_opening_hours', $this->opening === '' ? null : (string) $opening);

        $this->showingOpening = false;
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
        $sickness = $this->sicknessLedger();

        $days = $this->showingForm ? $this->daysInRange() : collect();
        $otherLeave = $ledger->otherLeave();

        return [
            'chosenDays' => $days,
            'chosenHours' => round($days->sum('hours'), 2),
            'leaveYear' => $year,
            'ledger' => $ledger,
            'tabs' => $this->tabs(),
            'requests' => $this->tab === 'sickness'
                ? $sickness->requests()
                : $ledger->requests()->filter(
                    fn (LeaveRequest $request): bool => in_array($request->days->first()?->type, $this->tabTypes(), true),
                ),
            'lieu' => $this->lieuLedger(),
            'otherLeave' => $otherLeave,
            'otherUsed' => round($otherLeave->sum('used'), 2),
            'sickness' => $sickness,
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

            {{-- Sick pay is not bounded by the leave year, so naming one here
                 would say the figures below were for a period they are not. --}}
            <h1 class="mt-1 text-2xl font-medium text-zinc-800 dark:text-white">
                {{ $tab === 'sickness' ? 'Rolling year to '.$sickness->asAt->format('j M Y') : $leaveYear->label() }}
            </h1>
        </div>

        <div class="flex items-center gap-1">
            <flux:button size="sm" variant="primary" icon="plus" wire:click="bookLeave">Book leave</flux:button>

            @unless ($tab === 'sickness')
                <flux:button icon="chevron-left" size="sm" variant="subtle" wire:click="shiftYear(-1)" aria-label="Previous leave year" />
                <flux:button size="sm" variant="subtle" wire:click="thisYear">This year</flux:button>
                <flux:button icon="chevron-right" size="sm" variant="subtle" wire:click="shiftYear(1)" aria-label="Next leave year" />
            @endunless
        </div>
    </div>


    {{-- Where each pot stands, before you open one. The summary is always on
         view; the working out is only there when you ask for it. --}}
    @php
        /*
         * Each pot wears its own hue on a thick leading edge, a tinted ground
         * and a medallion, so it is picked out as an object before a word of
         * it is read. The edge is solid rather than a wash, because a wash is
         * the first thing to vanish under reduced transparency and forced
         * colours; nothing here rests on the tint alone.
         */
        $pots = [
            'annual' => ['icon' => 'sun', 'edge' => 'border-s-pot-annual dark:border-s-pot-annual-bright', 'ink' => 'text-pot-annual dark:text-pot-annual-bright', 'wash' => 'bg-pot-annual/[0.06] dark:bg-pot-annual-bright/[0.08]', 'hover' => 'hover:bg-pot-annual/[0.06] dark:hover:bg-pot-annual-bright/[0.08]'],
            'lieu' => ['icon' => 'arrow-path', 'edge' => 'border-s-pot-lieu dark:border-s-pot-lieu-bright', 'ink' => 'text-pot-lieu dark:text-pot-lieu-bright', 'wash' => 'bg-pot-lieu/[0.06] dark:bg-pot-lieu-bright/[0.08]', 'hover' => 'hover:bg-pot-lieu/[0.06] dark:hover:bg-pot-lieu-bright/[0.08]'],
            'other' => ['icon' => 'squares-2x2', 'edge' => 'border-s-pot-other dark:border-s-pot-other-bright', 'ink' => 'text-pot-other dark:text-pot-other-bright', 'wash' => 'bg-pot-other/[0.06] dark:bg-pot-other-bright/[0.08]', 'hover' => 'hover:bg-pot-other/[0.06] dark:hover:bg-pot-other-bright/[0.08]'],
            'sickness' => ['icon' => 'heart', 'edge' => 'border-s-pot-sickness dark:border-s-pot-sickness-bright', 'ink' => 'text-pot-sickness dark:text-pot-sickness-bright', 'wash' => 'bg-pot-sickness/[0.06] dark:bg-pot-sickness-bright/[0.08]', 'hover' => 'hover:bg-pot-sickness/[0.06] dark:hover:bg-pot-sickness-bright/[0.08]'],
        ];

        $potBox = fn (string $key): string => 'overflow-hidden rounded-xl border border-s-4 transition '.$pots[$key]['edge'].' '.($tab === $key
            ? 'border-zinc-300 shadow-sm dark:border-zinc-600 '.$pots[$key]['wash']
            : 'border-zinc-200 hover:border-zinc-300 '.$pots[$key]['hover'].' dark:border-zinc-700 dark:hover:border-zinc-600');

        /* The medallion repeats the hue as an area rather than a line, which
           is what carries at a glance down a column of four. */
        $potMedallion = fn (string $key): string => 'flex size-9 shrink-0 items-center justify-center rounded-lg '
            .$pots[$key]['wash'].' '.$pots[$key]['ink'];

        $tileLabel = 'text-[0.7rem] font-medium uppercase tracking-[0.18em]';
        $potLabel = fn (string $key): string => $tileLabel.' '.$pots[$key]['ink'];
        $tileFigure = 'tabular text-2xl font-medium leading-none';
        $tileNote = 'mt-1 text-xs text-zinc-400 dark:text-zinc-500';
    @endphp

    {{-- Each pot is counted a different way, so each keeps its working out
         behind its own summary: open one and the detail belongs to it. --}}
    <section class="mt-6 flex flex-col gap-3">
        <div class="{{ $potBox('annual') }}" wire:key="pot-annual">
            <button
                type="button"
                class="flex w-full items-start justify-between gap-4 px-5 py-4 text-start"
                wire:click="togglePot('annual')"
                aria-expanded="{{ $tab === 'annual' ? 'true' : 'false' }}"
            >
                <span class="{{ $potMedallion('annual') }}" aria-hidden="true">
                    <flux:icon.sun variant="micro" />
                </span>

                <span class="min-w-0 flex-1">
                    <div class="{{ $potLabel('annual') }}">Annual leave</div>

                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="{{ $tileFigure }} {{ $ledger->isOverdrawn() ? 'text-deficit dark:text-deficit-bright' : 'text-zinc-800 dark:text-white' }}">
                            {{ $hours($ledger->annualRemaining()) }}
                        </span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            hours left of {{ $hours($ledger->annualEntitlement()) }}
                        </span>
                    </div>

                    <div class="{{ $tileNote }}">
                        <span class="tabular">{{ $hours($ledger->annualBooked()) }}</span>h booked
                        @if ($ledger->asDays($ledger->annualRemaining()) !== null)
                            &middot; about {{ $ledger->asDays($ledger->annualRemaining()) }} days left
                        @endif
                    </div>
                </span>

                <flux:icon.chevron-down class="mt-1 size-4 shrink-0 text-zinc-400 transition dark:text-zinc-500 {{ $tab === 'annual' ? 'rotate-180' : '' }}" />
            </button>

            @if ($tab === 'annual')
                <div class="border-t border-zinc-200 px-5 pb-5 dark:border-zinc-700">
                    {{-- One balance, with the public holiday adjustment shown as a line in it
                         rather than as a second pot to reconcile by eye. --}}
                    <section class="mt-5">
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

                    @include('pages.leave.requests', ['pot' => 'annual', 'hours' => $hours])
                </div>
            @endif
        </div>

        <div class="{{ $potBox('lieu') }}" wire:key="pot-lieu">
            <button
                type="button"
                class="flex w-full items-start justify-between gap-4 px-5 py-4 text-start"
                wire:click="togglePot('lieu')"
                aria-expanded="{{ $tab === 'lieu' ? 'true' : 'false' }}"
            >
                <span class="{{ $potMedallion('lieu') }}" aria-hidden="true">
                    <flux:icon.arrow-path variant="micro" />
                </span>

                <span class="min-w-0 flex-1">
                    <div class="{{ $potLabel('lieu') }}">Time off in lieu</div>

                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="{{ $tileFigure }} text-zinc-800 dark:text-white">{{ $hours($lieu->balance()) }}</span>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">hours banked</span>
                    </div>

                    <div class="{{ $tileNote }}">
                        @if ($lieu->isUsed())
                            <span class="tabular">{{ $hours($lieu->earned()) }}</span>h worked in lieu
                            &middot; <span class="tabular">{{ $hours($lieu->booked()) }}</span>h taken back
                        @else
                            Track time as lieu on the dashboard to bank it
                        @endif
                    </div>
                </span>

                <flux:icon.chevron-down class="mt-1 size-4 shrink-0 text-zinc-400 transition dark:text-zinc-500 {{ $tab === 'lieu' ? 'rotate-180' : '' }}" />
            </button>

            @if ($tab === 'lieu')
                <div class="border-t border-zinc-200 px-5 pb-5 dark:border-zinc-700">
                    {{-- Lieu is not awarded for a year: it is banked by working it and spent
                         by booking it, so it runs on across leave years and never goes
                         below zero. --}}
                    <section class="mt-5">
                        <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">Banked in lieu</div>

                        <div class="mt-1 flex items-baseline gap-3">
                            <span class="tabular text-4xl font-medium leading-none text-zinc-800 dark:text-white">
                                {{ $hours($lieu->balance()) }}
                            </span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                hours to take back
                                @if ($lieu->asDays($lieu->balance()) !== null && $lieu->balance() > 0)
                                    &middot; about {{ $lieu->asDays($lieu->balance()) }} days
                                @endif
                            </span>
                        </div>

                        <dl class="mt-5 flex max-w-md flex-col gap-1 text-sm">
                            <div class="flex items-baseline justify-between gap-2">
                                <dt class="flex items-baseline gap-2 text-zinc-500 dark:text-zinc-400">
                                    Opening balance
                                    <span class="text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ $lieu->openingWasEntered() ? 'entered' : 'configured' }}
                                    </span>
                                    <flux:button
                                        icon="pencil-square"
                                        size="xs"
                                        variant="subtle"
                                        inset="top bottom"
                                        wire:click="editOpening"
                                        aria-label="Change the opening lieu balance"
                                    />
                                </dt>
                                <dd class="tabular">{{ $hours($lieu->openingBalance()) }}</dd>
                            </div>

                            <div class="flex justify-between">
                                <dt class="text-zinc-500 dark:text-zinc-400">Worked in lieu since</dt>
                                <dd class="tabular text-surplus dark:text-surplus-bright">+{{ $hours($lieu->earned()) }}</dd>
                            </div>

                            <div class="flex justify-between">
                                <dt class="text-zinc-500 dark:text-zinc-400">Taken back as leave</dt>
                                <dd class="tabular">&minus;{{ $hours($lieu->booked()) }}</dd>
                            </div>

                            <div class="mt-1 flex justify-between border-t border-zinc-200 pt-1 font-medium dark:border-zinc-700">
                                <dt class="text-zinc-500 dark:text-zinc-400">Balance</dt>
                                <dd class="tabular">{{ $hours($lieu->balance()) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                        Lieu is banked by starting the timer as time off in lieu rather than as contracted hours, so those hours
                        stay out of your flexi balance and wait here instead. You can only book what you have already worked: the
                        balance never goes below zero. It carries on across leave years, which is why this pot ignores the year
                        above.
                    </p>

                    @include('pages.leave.requests', ['pot' => 'lieu', 'hours' => $hours])
                </div>
            @endif
        </div>

        <div class="{{ $potBox('other') }}" wire:key="pot-other">
            <button
                type="button"
                class="flex w-full items-start justify-between gap-4 px-5 py-4 text-start"
                wire:click="togglePot('other')"
                aria-expanded="{{ $tab === 'other' ? 'true' : 'false' }}"
            >
                <span class="{{ $potMedallion('other') }}" aria-hidden="true">
                    <flux:icon.squares-2x2 variant="micro" />
                </span>

                <span class="min-w-0 flex-1">
                    <div class="{{ $potLabel('other') }}">Other leave</div>

                    @if ($otherLeave->isEmpty())
                        <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Nothing booked this year</div>
                        <div class="{{ $tileNote }}">None of it comes off your annual leave</div>
                    @else
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="{{ $tileFigure }} text-zinc-800 dark:text-white">{{ $hours($otherUsed) }}</span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                hours taken across {{ $otherLeave->count() }} {{ Str::plural('type', $otherLeave->count()) }}
                            </span>
                        </div>

                        <div class="{{ $tileNote }}">
                            @php $overdrawn = $otherLeave->filter(fn ($other) => $other->isOverdrawn()); @endphp

                            @if ($overdrawn->isNotEmpty())
                                <span class="text-deficit dark:text-deficit-bright">
                                    {{ $overdrawn->map(fn ($other) => $other->type->label())->join(', ', ' and ') }}
                                    past its allowance
                                </span>
                            @else
                                Counted apart from your annual leave
                            @endif
                        </div>
                    @endif
                </span>

                <flux:icon.chevron-down class="mt-1 size-4 shrink-0 text-zinc-400 transition dark:text-zinc-500 {{ $tab === 'other' ? 'rotate-180' : '' }}" />
            </button>

            @if ($tab === 'other')
                <div class="border-t border-zinc-200 px-5 pb-5 dark:border-zinc-700">
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

                    @include('pages.leave.requests', ['pot' => 'other', 'hours' => $hours])
                </div>
            @endif
        </div>

        <div class="{{ $potBox('sickness') }}" wire:key="pot-sickness">
            <button
                type="button"
                class="flex w-full items-start justify-between gap-4 px-5 py-4 text-start"
                wire:click="togglePot('sickness')"
                aria-expanded="{{ $tab === 'sickness' ? 'true' : 'false' }}"
            >
                <span class="{{ $potMedallion('sickness') }}" aria-hidden="true">
                    <flux:icon.heart variant="micro" />
                </span>

                <span class="min-w-0 flex-1">
                    <div class="{{ $potLabel('sickness') }}">Sickness</div>

                    @unless ($sickness->isConfigured())
                        <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">No length of service set</div>
                        <div class="{{ $tileNote }}">There is nothing to work sick pay out from</div>
                    @else
                        {{-- What has been taken, not what is left to take. A
                             remaining figure reads as a budget however it is
                             worded, and sick pay is not there to be spent: the
                             honest headline is the record of what illness has
                             actually needed. --}}
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="{{ $tileFigure }} text-pot-sickness dark:text-pot-sickness-bright">
                                {{ $hours($sickness->used()) }}
                            </span>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                hours of sickness taken
                            </span>
                        </div>

                        {{-- Sick pay runs over the twelve months before an absence, so it
                             stays as at today however far the leave year is wound back. --}}
                        <div class="{{ $tileNote }}">
                            @if ($sickness->fullPayRemaining() > 0)
                                still covered at full pay
                            @elseif ($sickness->halfPayRemaining() > 0)
                                <span class="font-medium text-deficit dark:text-deficit-bright">now at half pay</span>
                            @else
                                <span class="font-medium text-deficit dark:text-deficit-bright">no paid cover left</span>
                            @endif
                            &middot; rolling year to {{ $sickness->asAt->format('j M Y') }}
                        </div>

                        {{-- Where the sickness taken falls across the two bands
                             of cover, so how far in you are is seen rather than
                             worked out from three figures. Each band is a ground
                             of its own; the fill is what has been used. --}}
                        @php
                            $coverTotal = $sickness->fullPayEntitlement() + $sickness->halfPayEntitlement();
                            $fullShare = $coverTotal > 0 ? $sickness->fullPayEntitlement() / $coverTotal * 100 : 0;
                            $usedShare = $coverTotal > 0 ? min(100, $sickness->used() / $coverTotal * 100) : 0;
                        @endphp

                        @if ($coverTotal > 0)
                            <div class="relative mt-3 h-2.5 max-w-md overflow-hidden rounded-full" aria-hidden="true">
                                <div class="absolute inset-y-0 left-0 bg-pot-sickness/20 dark:bg-pot-sickness-bright/25" style="width: {{ $fullShare }}%"></div>
                                <div class="absolute inset-y-0 right-0 bg-pot-sickness/10 dark:bg-pot-sickness-bright/12" style="width: {{ 100 - $fullShare }}%"></div>

                                <div class="absolute inset-y-0 left-0 rounded-full bg-pot-sickness dark:bg-pot-sickness-bright" style="width: {{ $usedShare }}%"></div>

                                <div class="absolute inset-y-0 w-px bg-white dark:bg-zinc-900" style="left: {{ $fullShare }}%"></div>
                            </div>

                            <div class="{{ $tileNote }} flex max-w-md justify-between">
                                <span>full pay to <span class="tabular">{{ $hours($sickness->fullPayEntitlement()) }}</span>h</span>
                                <span>half pay to <span class="tabular">{{ $hours($coverTotal) }}</span>h</span>
                            </div>
                        @endif
                    @endunless
                </span>

                <flux:icon.chevron-down class="mt-1 size-4 shrink-0 text-zinc-400 transition dark:text-zinc-500 {{ $tab === 'sickness' ? 'rotate-180' : '' }}" />
            </button>

            @if ($tab === 'sickness')
                <div class="border-t border-zinc-200 px-5 pb-5 dark:border-zinc-700">
                    {{-- Occupational sick pay. Banded by length of service and reduced by
                         the sickness taken in the twelve months before the absence begins,
                         so it belongs to a rolling year rather than to a leave year. It is
                         shown as at today, which is what is being counted right now. --}}
                    <section class="mt-5">
                        <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                            Sickness allowance
                        </h2>

                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                            Counting the sickness taken between {{ $sickness->windowLabel() }}.
                            Anything older has rolled out of the window.
                        </p>

                        @if (! $sickness->isConfigured())
                            <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                                Set <span class="font-mono text-xs">service_started</span> in your leave config to see what sick pay
                                your length of service earns. Until then there is nothing to work it out from.
                            </p>
                        @else
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ([['Full pay', $sickness->fullPayRemaining(), $sickness->fullPayEntitlement()], ['Half pay', $sickness->halfPayRemaining(), $sickness->halfPayEntitlement()]] as [$label, $left, $total])
                                    <div class="rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                        <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                                            {{ $label }}
                                        </div>

                                        <div class="mt-1 flex items-baseline gap-2">
                                            <span class="tabular text-2xl font-medium leading-none {{ $left <= 0 ? 'text-deficit dark:text-deficit-bright' : 'text-pot-sickness dark:text-pot-sickness-bright' }}">
                                                {{ $hours($total - $left) }}
                                            </span>
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">
                                                of {{ $hours($total) }} hours used
                                            </span>
                                        </div>

                                        {{-- The months line follows the figure above it:
                                             what is still there to fall back on, once
                                             what has been used is the headline. --}}
                                        @if ($sickness->asMonths($left) !== null)
                                            <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                                about {{ $sickness->asMonths($left) }} {{ Str::plural('month', $sickness->asMonths($left)) }} of pay still covered
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                                Your {{ $sickness->yearOfServiceLabel() }}
                                earns {{ $sickness->band()->label() }}, worth
                                <span class="tabular">{{ $hours($sickness->monthHours()) }}</span>h a month at your pattern.
                                @if ($sickness->used() > 0)
                                    <span class="tabular">{{ $hours($sickness->used()) }}</span>h of sickness since
                                    {{ $sickness->windowStart()->format('j M Y') }} has already come off it.
                                @else
                                    No sickness has been recorded since {{ $sickness->windowStart()->format('j M Y') }}.
                                @endif
                            </p>
                        @endif
                    </section>

                    @include('pages.leave.requests', ['pot' => 'sickness', 'hours' => $hours])
                </div>
            @endif
        </div>
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
                    Only annual leave comes off your bookable balance. Time off in lieu comes off what you have
                    banked by working it.
                </flux:description>
                <flux:error name="type" />
            </flux:field>

            <div class="flex items-end gap-3">
                <flux:field>
                    <flux:label>From</flux:label>
                    <flux:input type="date" wire:model.live="from" />
                </flux:field>

                <flux:field>
                    <flux:label>To</flux:label>
                    <flux:input type="date" wire:model.live="to" />
                </flux:field>
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

    <flux:modal wire:model="showingOpening" class="max-w-sm">
        <form wire:submit="saveOpening" class="flex flex-col gap-4">
            <flux:heading size="lg">Opening lieu balance</flux:heading>

            <flux:field>
                <flux:label>Hours already owed to you</flux:label>
                <flux:description>
                    What you were owed in lieu before you started tracking it here. Everything since is worked out
                    from your sessions.
                </flux:description>
                <flux:input type="number" step="0.25" min="0" wire:model="opening"
                            placeholder="{{ $hours($lieu->openingBalance()) }}" />
                <flux:error name="opening" />
            </flux:field>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Save</flux:button>
                <flux:button variant="ghost" wire:click="$set('showingOpening', false)">Cancel</flux:button>
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
