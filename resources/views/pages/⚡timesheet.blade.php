<?php

use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    /**
     * Day steps through single days, week shows a working week, period shows
     * all six weeks at once.
     */
    #[Url]
    public string $mode = 'week';

    /**
     * The date the view is built around. Switching mode keeps it, so moving
     * from a day to its week to its period never loses your place.
     */
    #[Url]
    public ?string $focus = null;

    /**
     * Show every day in the week, not only the ones the pattern works.
     */
    #[Url]
    public bool $allDays = false;

    public ?int $editingId = null;

    public string $date = '';

    public string $start_time = '';

    public string $end_time = '';

    public bool $showingForm = false;

    /**
     * Start on today.
     */
    public function mount(): void
    {
        $this->focus ??= now()->toDateString();
    }

    /**
     * Get the date the view is built around.
     */
    public function focusDate(): Carbon
    {
        return Carbon::parse($this->focus ?? now()->toDateString())->startOfDay();
    }

    /**
     * Switch between the day, week and period views.
     */
    public function showMode(string $mode): void
    {
        $this->mode = in_array($mode, ['day', 'week', 'period'], strict: true) ? $mode : 'week';
    }

    /**
     * Step forward or back by whatever the current view shows.
     */
    public function shift(int $direction): void
    {
        $this->focus = match ($this->mode) {
            'day' => $this->focusDate()->addDays($direction)->toDateString(),
            'period' => FlexiPeriod::forDate($this->focusDate())->start
                ->addDays($direction * FlexiPeriod::length())->toDateString(),
            default => $this->focusDate()->addWeeks($direction)->toDateString(),
        };
    }

    /**
     * Open a single day from the week view.
     */
    public function showDay(string $date): void
    {
        $this->focus = Carbon::parse($date)->toDateString();
        $this->mode = 'day';
    }

    /**
     * Open a week from the period view.
     */
    public function showWeek(string $date): void
    {
        $this->focus = Carbon::parse($date)->toDateString();
        $this->mode = 'week';
    }

    /**
     * Return to today.
     */
    public function today(): void
    {
        $this->focus = now()->toDateString();
    }

    /**
     * Open the form to add a session on the given date.
     */
    public function addSession(string $date): void
    {
        $this->reset('editingId', 'start_time', 'end_time');
        $this->resetValidation();

        $this->date = $date;
        $this->start_time = '09:00';
        $this->end_time = '17:00';
        $this->showingForm = true;
    }

    /**
     * Open the form to edit an existing session.
     */
    public function editSession(WorkSession $session): void
    {
        $this->resetValidation();

        $this->editingId = $session->id;
        $this->date = $session->date()->toDateString();
        $this->start_time = $session->started_at->format('H:i');
        $this->end_time = $session->ended_at?->format('H:i') ?? '';
        $this->showingForm = true;
    }

    /**
     * Save the session being added or edited.
     */
    public function saveSession(): void
    {
        $validated = $this->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ], [
            'end_time.after' => 'The end time must be after the start time.',
        ]);

        $attributes = [
            'started_at' => Carbon::parse("{$validated['date']} {$validated['start_time']}"),
            'ended_at' => Carbon::parse("{$validated['date']} {$validated['end_time']}"),
        ];

        if ($this->editingId !== null) {
            WorkSession::query()->findOrFail($this->editingId)->update($attributes);
        } else {
            WorkSession::query()->create($attributes);
        }

        $this->showingForm = false;
    }

    /**
     * Delete a session.
     */
    public function deleteSession(WorkSession $session): void
    {
        $session->delete();
    }

    /**
     * Build the period, then whichever slice the current view needs.
     */
    public function with(): array
    {
        $focus = $this->focusDate();
        $period = FlexiPeriod::forDate($focus);
        $calculator = new FlexiCalculator;
        $calculator->warmRange($period->start, $period->end);

        $sessions = WorkSession::query()
            ->whereBetween('started_at', [$period->start, $period->end])
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (WorkSession $session): string => $session->date()->toDateString());

        $weeks = $period->weekStarts()->map(function (Carbon $start, int $index) use ($sessions, $calculator, $focus): array {
            $days = collect(range(0, 6))->map(fn (int $offset): array => $this->buildDay(
                $start->copy()->addDays($offset), $sessions, $calculator,
            ));

            $counted = $days->reject(fn (array $day): bool => $day['isFuture']);

            return [
                'number' => $index + 1,
                'start' => $start,
                'days' => $days,
                'worked' => round($counted->sum('worked'), 2),
                'expected' => round($counted->sum('expected'), 2),
                'delta' => round($counted->sum('delta'), 2),
                'hasCountedDays' => $counted->isNotEmpty(),
                'isCurrent' => $start->isSameWeek(now()),
                'isFocused' => $start->isSameWeek($focus),
            ];
        });

        $balance = $calculator->balanceFor($period);
        $limit = FlexiPeriod::limit();

        $allDays = $weeks->flatMap(fn (array $week) => $week['days']);
        $scale = max(4.0, $allDays->reject(fn (array $day): bool => $day['isFuture'])
            ->map(fn (array $day): float => abs($day['delta']))->max() ?? 0.0);

        return [
            'focus' => $focus,
            'flexiPeriod' => $period,
            'weeks' => $weeks,
            'day' => $this->buildDay($focus, $sessions, $calculator),
            'week' => $weeks->firstWhere('isFocused') ?? $weeks->first(),
            'scale' => $scale,
            'weekScale' => max(4.0, $weeks->filter(fn (array $week): bool => $week['hasCountedDays'])
                ->map(fn (array $week): float => abs($week['delta']))->max() ?? 0.0),
            'balance' => $balance,
            'carry' => $calculator->carryInto($period),
            'isOverLimit' => $calculator->isOverLimit($balance),
            'limit' => $limit,
            'gaugePosition' => (max(-$limit, min($limit, $balance)) + $limit) / (2 * $limit) * 100,
            'daysLeft' => max(0, (int) now()->startOfDay()->diffInDays($period->end->copy()->startOfDay(), false)),
            'trackingStart' => FlexiPeriod::trackingStart(),
            'showsTrackingStart' => $period->startsPartWayThrough(),
            'stepLabel' => match ($this->mode) {
                'day' => 'Today',
                'period' => 'This period',
                default => 'This week',
            },
        ];
    }

    /**
     * Gather everything a single day needs.
     *
     * @param  Collection<string, Collection<int, WorkSession>>  $sessions
     * @return array<string, mixed>
     */
    protected function buildDay(Carbon $date, Collection $sessions, FlexiCalculator $calculator): array
    {
        $key = $date->toDateString();
        $onDay = $sessions->get($key, collect());

        return [
            'date' => $date,
            'key' => $key,
            'sessions' => $onDay,
            'worked' => $calculator->workedHours($date),
            'expected' => $calculator->expectedHours($date),
            'delta' => $calculator->deltaFor($date),
            'isToday' => $date->isToday(),
            'isFocused' => $date->isSameDay($this->focusDate()),
            'isFuture' => $date->copy()->startOfDay()->isFuture(),
            'isTracked' => $calculator->isTracked($date),
            'bankHoliday' => $calculator->bankHolidayTitle($date),
            'isVisible' => $this->allDays
                || $calculator->isPatternWorkingDay($date)
                || $onDay->isNotEmpty()
                || $date->isToday(),
        ];
    }
}; ?>

@php
    $tone = fn (float $v): string => $v > 0
        ? 'text-surplus dark:text-surplus-bright'
        : ($v < 0 ? 'text-deficit dark:text-deficit-bright' : 'text-zinc-400 dark:text-zinc-500');
    $signed = fn (float $v): string => ($v > 0 ? '+' : '').number_format($v, 2);
@endphp

<div class="mx-auto max-w-6xl">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                Timesheet
            </div>
            <h1 class="mt-1 text-2xl font-medium text-zinc-800 dark:text-white">
                @if ($mode === 'day')
                    {{ $focus->format('l j F Y') }}
                @elseif ($mode === 'week')
                    Week {{ $week['number'] }} &middot; {{ $week['start']->format('j M') }} &ndash; {{ $week['start']->copy()->addDays(6)->format('j M Y') }}
                @else
                    {{ $flexiPeriod->label() }}
                @endif
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-1">
            <flux:button.group class="me-3">
                <flux:button size="sm" :variant="$mode === 'day' ? 'filled' : 'subtle'" wire:click="showMode('day')">Day</flux:button>
                <flux:button size="sm" :variant="$mode === 'week' ? 'filled' : 'subtle'" wire:click="showMode('week')">Week</flux:button>
                <flux:button size="sm" :variant="$mode === 'period' ? 'filled' : 'subtle'" wire:click="showMode('period')">Period</flux:button>
            </flux:button.group>

            @if ($mode === 'week')
                <flux:switch wire:model.live="allDays" label="All days" class="me-3" />
            @endif

            <flux:button icon="chevron-left" size="sm" variant="subtle" wire:click="shift(-1)" aria-label="Previous {{ $mode }}" />
            <flux:button size="sm" variant="subtle" wire:click="today">{{ $stepLabel }}</flux:button>
            <flux:button icon="chevron-right" size="sm" variant="subtle" wire:click="shift(1)" aria-label="Next {{ $mode }}" />
        </div>
    </div>

    {{-- The balance always frames whatever you are looking at, because every
         view is a slice of the same period. --}}
    <section class="mt-6 rounded-xl border border-zinc-200 bg-ledger px-5 py-5 dark:border-zinc-700 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <div>
                <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">Balance</div>
                <div class="mt-1 flex items-baseline gap-3">
                    <span class="tabular text-4xl font-medium leading-none {{ $tone($balance) }}">{{ $signed($balance) }}</span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $balance > 0 ? 'hours banked' : ($balance < 0 ? 'hours owed' : 'in balance') }}
                    </span>
                </div>
            </div>

            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $flexiPeriod->label() }}
                &middot;
                @if ($daysLeft > 0)
                    {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} left
                @else
                    closed
                @endif
                @if ($carry != 0)
                    &middot; <span class="tabular">{{ $signed($carry) }}</span> carried in
                @endif
                @if ($showsTrackingStart)
                    &middot; Tracking starts {{ $trackingStart->format('j M Y') }}
                @endif
            </div>
        </div>

        <div class="relative mt-5 h-8">
            <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-zinc-300 dark:bg-zinc-600"></div>
            <div class="absolute left-0 top-1/2 h-3 w-px -translate-y-1/2 bg-zinc-400 dark:bg-zinc-500"></div>
            <div class="absolute right-0 top-1/2 h-3 w-px -translate-y-1/2 bg-zinc-400 dark:bg-zinc-500"></div>
            <div class="absolute left-1/2 top-1/2 h-5 w-px -translate-y-1/2 bg-zinc-400 dark:bg-zinc-500"></div>

            @php $from = min(50, $gaugePosition); $to = max(50, $gaugePosition); @endphp
            <div class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full @if ($balance > 0) bg-surplus dark:bg-surplus-bright @else bg-deficit dark:bg-deficit-bright @endif"
                 style="left: {{ $from }}%; width: {{ max(0.4, $to - $from) }}%"></div>
            <div class="absolute top-1/2 h-5 w-0.5 -translate-x-1/2 -translate-y-1/2 rounded-full @if ($balance > 0) bg-surplus dark:bg-surplus-bright @elseif ($balance < 0) bg-deficit dark:bg-deficit-bright @else bg-zinc-500 @endif"
                 style="left: {{ $gaugePosition }}%"></div>

            @if ($isOverLimit)
                <div class="absolute top-1/2 -translate-y-1/2 text-xs font-medium text-deficit dark:text-deficit-bright"
                     style="{{ $balance > 0 ? 'right: -0.25rem' : 'left: -0.25rem' }}">{{ $balance > 0 ? '▸' : '◂' }}</div>
            @endif
        </div>

        <div class="mt-2 flex justify-between text-[0.7rem] uppercase tracking-[0.12em] text-zinc-400 dark:text-zinc-500">
            <span class="tabular">&minus;{{ $limit }}h</span><span>Zero</span><span class="tabular">+{{ $limit }}h</span>
        </div>

        @if ($isOverLimit)
            <p class="mt-3 text-sm text-deficit dark:text-deficit-bright">
                Over the {{ $limit }}h limit by <span class="tabular">{{ number_format(abs($balance) - $limit, 2) }}</span>h.
                @if ($balance > 0) Only {{ $limit }}h carries into the next period. @endif
            </p>
        @endif
    </section>

    @include('pages.timesheet.'.$mode)

    <flux:modal wire:model="showingForm" class="max-w-sm">
        <form wire:submit="saveSession" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit entry' : 'Add entry' }}</flux:heading>

            <flux:field>
                <flux:label>Date</flux:label>
                <flux:input type="date" wire:model="date" />
                <flux:error name="date" />
            </flux:field>

            <div class="flex items-end gap-3">
                <flux:field>
                    <flux:label>From</flux:label>
                    <flux:input type="time" wire:model="start_time" />
                </flux:field>

                <flux:field>
                    <flux:label>To</flux:label>
                    <flux:input type="time" wire:model="end_time" />
                </flux:field>
            </div>

            <flux:error name="start_time" />
            <flux:error name="end_time" />

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Save</flux:button>
                <flux:button variant="ghost" wire:click="$set('showingForm', false)">Cancel</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
