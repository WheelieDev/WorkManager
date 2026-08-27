<?php

use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    /**
     * The index of the period being viewed.
     */
    #[Url]
    public ?int $period = null;

    /**
     * Show every day, including ones the pattern does not work.
     */
    #[Url]
    public bool $allDays = false;

    /**
     * Either 'days' for the editable grid, or 'weeks' for the summary.
     */
    #[Url]
    public string $view = 'days';

    /**
     * Switch between the day grid and the week summary.
     */
    public function showView(string $view): void
    {
        $this->view = in_array($view, ['days', 'weeks'], strict: true) ? $view : 'days';
    }

    public ?int $editingId = null;

    public string $date = '';

    public string $start_time = '';

    public string $end_time = '';

    public bool $showingForm = false;

    /**
     * Default to the period containing today.
     */
    public function mount(): void
    {
        $this->period ??= FlexiPeriod::current()->index;
    }

    /**
     * Get the period being viewed.
     */
    public function period(): FlexiPeriod
    {
        return new FlexiPeriod($this->period ?? FlexiPeriod::current()->index);
    }

    /**
     * Move to an earlier or later period.
     */
    public function shiftPeriod(int $periods): void
    {
        $this->period = $this->period()->index + $periods;
    }

    /**
     * Jump back to the period containing today.
     */
    public function thisPeriod(): void
    {
        $this->period = FlexiPeriod::current()->index;
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
     * Build every week of the period, with day and week totals.
     */
    public function with(): array
    {
        $period = $this->period();
        $calculator = new FlexiCalculator;
        $calculator->warmRange($period->start, $period->end);

        $sessions = WorkSession::query()
            ->whereBetween('started_at', [$period->start, $period->end])
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (WorkSession $session): string => $session->date()->toDateString());

        $weeks = $period->weekStarts()->map(function (Carbon $start, int $index) use ($sessions, $calculator): array {
            $days = collect(range(0, 6))->map(function (int $offset) use ($start, $sessions, $calculator): array {
                $date = $start->copy()->addDays($offset);
                $key = $date->toDateString();

                return [
                    'date' => $date,
                    'key' => $key,
                    'sessions' => $sessions->get($key, collect()),
                    'worked' => $calculator->workedHours($date),
                    'expected' => $calculator->expectedHours($date),
                    'delta' => $calculator->deltaFor($date),
                    'isToday' => $date->isToday(),
                    'isFuture' => $date->startOfDay()->isFuture(),
                    'bankHoliday' => $calculator->bankHolidayTitle($date),
                    // A day is worth a box if the pattern works it, if
                    // something was recorded on it, or if it is today.
                    'isVisible' => $this->allDays
                        || $calculator->isPatternWorkingDay($date)
                        || $sessions->get($key, collect())->isNotEmpty()
                        || $date->isToday(),
                ];
            });

            // Days that have not happened yet are excluded, so the week totals
            // agree with the balance rather than counting hours not yet owed.
            $counted = $days->reject(fn (array $day): bool => $day['isFuture']);

            return [
                'number' => $index + 1,
                'start' => $start,
                'days' => $days->filter(fn (array $day): bool => $day['isVisible'])->values(),
                'worked' => round($counted->sum('worked'), 2),
                'expected' => round($counted->sum('expected'), 2),
                'delta' => round($counted->sum('delta'), 2),
                'hasCountedDays' => $counted->isNotEmpty(),
                'isCurrent' => $start->isSameWeek(now()),
            ];
        });

        $balance = $calculator->balanceFor($period);
        $limit = FlexiPeriod::limit();

        // Bars are drawn against the largest swing in the period, with a floor
        // so a quiet period does not exaggerate a half hour.
        $scale = max(
            4.0,
            $weeks->flatMap(fn (array $week) => $week['days'])
                ->reject(fn (array $day): bool => $day['isFuture'])
                ->map(fn (array $day): float => abs($day['delta']))
                ->max() ?? 0.0,
        );

        // The week summary is drawn against the largest week swing, so a
        // period of small weeks still reads clearly.
        $weekScale = max(
            4.0,
            $weeks->filter(fn (array $week): bool => $week['hasCountedDays'])
                ->map(fn (array $week): float => abs($week['delta']))
                ->max() ?? 0.0,
        );

        return [
            'scale' => $scale,
            'weekScale' => $weekScale,
            'gaugePosition' => (max(-$limit, min($limit, $balance)) + $limit) / (2 * $limit) * 100,
            'daysLeft' => max(0, (int) now()->startOfDay()->diffInDays($period->end->copy()->startOfDay(), false)),
            'weeks' => $weeks,
            'flexiPeriod' => $period,
            'balance' => $balance,
            'carry' => $calculator->carryInto($period),
            'isOverLimit' => $calculator->isOverLimit($balance),
            'limit' => $limit,
        ];
    }
}; ?>

<div class="mx-auto max-w-6xl">
    {{-- Header: the period is the frame, so it is named first and plainly. --}}
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                Timesheet
            </div>
            <h1 class="mt-1 text-2xl font-medium text-zinc-800 dark:text-white">
                {{ $flexiPeriod->label() }}
            </h1>
        </div>

        <div class="flex items-center gap-1">
            <flux:button.group class="me-3">
                <flux:button size="sm" :variant="$view === 'days' ? 'filled' : 'subtle'" wire:click="showView('days')">Days</flux:button>
                <flux:button size="sm" :variant="$view === 'weeks' ? 'filled' : 'subtle'" wire:click="showView('weeks')">Weeks</flux:button>
            </flux:button.group>

            @if ($view === 'days')
                <flux:switch wire:model.live="allDays" label="All days" class="me-3" />
            @endif
            <flux:button icon="chevron-left" size="sm" variant="subtle" wire:click="shiftPeriod(-1)" aria-label="Previous period" />
            <flux:button size="sm" variant="subtle" wire:click="thisPeriod">This period</flux:button>
            <flux:button icon="chevron-right" size="sm" variant="subtle" wire:click="shiftPeriod(1)" aria-label="Next period" />
        </div>
    </div>

    {{-- The gauge. The balance rides the scale rather than floating above it,
         and overshoots the cap when the limit is breached. --}}
    @php
        $tone = $balance > 0 ? 'surplus' : ($balance < 0 ? 'deficit' : 'zinc');
    @endphp

    <section class="mt-6 rounded-xl border border-zinc-200 bg-ledger px-5 py-5 dark:border-zinc-700 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <div>
                <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
                    Balance
                </div>
                <div class="mt-1 flex items-baseline gap-3">
                    <span class="tabular text-4xl font-medium leading-none @if ($balance > 0) text-surplus dark:text-surplus-bright @elseif ($balance < 0) text-deficit dark:text-deficit-bright @else text-zinc-500 dark:text-zinc-400 @endif">
                        {{ $balance > 0 ? '+' : '' }}{{ number_format($balance, 2) }}
                    </span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $balance > 0 ? 'hours banked' : ($balance < 0 ? 'hours owed' : 'in balance') }}
                    </span>
                </div>
            </div>

            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                @if ($daysLeft > 0)
                    {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} left in this period
                @else
                    Period closed
                @endif
                @if ($carry != 0)
                    &middot; <span class="tabular">{{ $carry > 0 ? '+' : '' }}{{ number_format($carry, 2) }}</span> carried in
                @endif
            </div>
        </div>

        <div class="relative mt-5 h-8">
            {{-- track --}}
            <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-zinc-300 dark:bg-zinc-600"></div>
            {{-- end caps and centre --}}
            <div class="absolute left-0 top-1/2 h-3 w-px -translate-y-1/2 bg-zinc-400 dark:bg-zinc-500"></div>
            <div class="absolute right-0 top-1/2 h-3 w-px -translate-y-1/2 bg-zinc-400 dark:bg-zinc-500"></div>
            <div class="absolute left-1/2 top-1/2 h-5 w-px -translate-y-1/2 bg-zinc-400 dark:bg-zinc-500"></div>

            {{-- fill from zero to the balance --}}
            @php
                $from = min(50, $gaugePosition);
                $to = max(50, $gaugePosition);
            @endphp
            <div
                class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full @if ($balance > 0) bg-surplus dark:bg-surplus-bright @else bg-deficit dark:bg-deficit-bright @endif"
                style="left: {{ $from }}%; width: {{ max(0.4, $to - $from) }}%"
            ></div>

            {{-- marker --}}
            <div
                class="absolute top-1/2 h-5 w-0.5 -translate-x-1/2 -translate-y-1/2 rounded-full @if ($balance > 0) bg-surplus dark:bg-surplus-bright @elseif ($balance < 0) bg-deficit dark:bg-deficit-bright @else bg-zinc-500 @endif"
                style="left: {{ $gaugePosition }}%"
            ></div>

            @if ($isOverLimit)
                <div class="absolute top-1/2 -translate-y-1/2 text-xs font-medium text-deficit dark:text-deficit-bright"
                     style="{{ $balance > 0 ? 'right: -0.25rem' : 'left: -0.25rem' }}">
                    {{ $balance > 0 ? '▸' : '◂' }}
                </div>
            @endif
        </div>

        <div class="mt-2 flex justify-between text-[0.7rem] uppercase tracking-[0.12em] text-zinc-400 dark:text-zinc-500">
            <span class="tabular">&minus;{{ $limit }}h</span>
            <span>Zero</span>
            <span class="tabular">+{{ $limit }}h</span>
        </div>

        @if ($isOverLimit)
            <p class="mt-3 text-sm text-deficit dark:text-deficit-bright">
                Over the {{ $limit }}h limit by <span class="tabular">{{ number_format(abs($balance) - $limit, 2) }}</span>h.
                @if ($balance > 0) Only {{ $limit }}h carries into the next period. @endif
            </p>
        @endif
    </section>

    @if ($view === 'weeks')
        {{-- The same scale turned on its side: one bar a week, running left or
             right of a single zero axis down the page. --}}
        <section class="mt-8">
            <div class="mb-2 flex items-baseline justify-between border-b border-zinc-200 pb-1.5 text-[0.7rem] font-medium uppercase tracking-[0.16em] text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
                <span>Week</span>
                <span>Worked / expected &middot; delta</span>
            </div>

            <div class="flex flex-col">
                @foreach ($weeks as $week)
                    @php
                        $weekFraction = $week['hasCountedDays'] && $weekScale > 0
                            ? min(1, abs($week['delta']) / $weekScale)
                            : 0;
                    @endphp

                    <div
                        wire:key="weekbar-{{ $week['start']->toDateString() }}"
                        @class([
                            'flex items-center gap-3 border-b border-zinc-100 py-2 dark:border-zinc-800',
                            'opacity-50' => ! $week['hasCountedDays'],
                        ])
                    >
                        <div class="flex w-28 shrink-0 items-baseline gap-2">
                            <span class="text-[0.7rem] font-medium uppercase tracking-[0.16em] {{ $week['isCurrent'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                                Week {{ $week['number'] }}
                            </span>
                            <span class="tabular text-xs text-zinc-400 dark:text-zinc-500">{{ $week['start']->format('j M') }}</span>
                        </div>

                        <div class="relative h-6 flex-1">
                            <div class="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-zinc-300 dark:bg-zinc-600"></div>

                            @if ($week['hasCountedDays'])
                                @if ($week['delta'] != 0)
                                    <div
                                        class="absolute top-1/2 h-2.5 -translate-y-1/2 rounded-[1px] @if ($week['delta'] > 0) left-1/2 bg-surplus dark:bg-surplus-bright @else right-1/2 bg-deficit dark:bg-deficit-bright @endif"
                                        style="width: {{ max(0.5, $weekFraction * 50) }}%"
                                    ></div>
                                @else
                                    <div class="absolute left-1/2 top-1/2 h-2.5 w-1 -translate-x-1/2 -translate-y-1/2 rounded-[1px] bg-zinc-300 dark:bg-zinc-600"></div>
                                @endif
                            @endif

                            @if ($week['isCurrent'])
                                <span class="absolute right-0 top-1/2 -translate-y-1/2 rounded-sm bg-zinc-800 px-1.5 py-0.5 text-[0.65rem] font-medium uppercase tracking-wider text-white dark:bg-white dark:text-zinc-900">This week</span>
                            @endif
                        </div>

                        <div class="flex w-40 shrink-0 items-baseline justify-end gap-2.5 text-xs">
                            @if ($week['hasCountedDays'])
                                <span class="tabular text-zinc-400 dark:text-zinc-500">
                                    {{ number_format($week['worked'], 2) }} / {{ number_format($week['expected'], 2) }}
                                </span>
                                <span class="tabular w-14 text-right font-medium @if ($week['delta'] > 0) text-surplus dark:text-surplus-bright @elseif ($week['delta'] < 0) text-deficit dark:text-deficit-bright @else text-zinc-400 dark:text-zinc-500 @endif">
                                    {{ $week['delta'] > 0 ? '+' : '' }}{{ number_format($week['delta'], 2) }}
                                </span>
                            @else
                                <span class="text-zinc-400 dark:text-zinc-500">Not started</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-3 text-sm text-zinc-400 dark:text-zinc-500">
                Switch to Days to add or change hours.
            </p>
        </section>
    @else
    {{-- The ledger. Each day draws its deviation from a shared zero line, so a
         week reads in one pass instead of cell by cell. --}}
    <div class="mt-8 flex flex-col gap-6">
        @foreach ($weeks as $week)
            <section wire:key="week-{{ $week['start']->toDateString() }}">
                <div class="mb-2 flex items-baseline justify-between gap-3 border-b border-zinc-200 pb-1.5 dark:border-zinc-700">
                    <div class="flex items-baseline gap-2.5">
                        <span class="text-[0.7rem] font-medium uppercase tracking-[0.16em] {{ $week['isCurrent'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                            Week {{ $week['number'] }}
                        </span>
                        <span class="tabular text-xs text-zinc-400 dark:text-zinc-500">{{ $week['start']->format('j M') }}</span>
                        @if ($week['isCurrent'])
                            <span class="rounded-sm bg-zinc-800 px-1.5 py-0.5 text-[0.65rem] font-medium uppercase tracking-wider text-white dark:bg-white dark:text-zinc-900">This week</span>
                        @endif
                    </div>

                    @if ($week['hasCountedDays'])
                        <div class="flex items-baseline gap-2.5 text-xs">
                            <span class="tabular text-zinc-400 dark:text-zinc-500">
                                {{ number_format($week['worked'], 2) }} / {{ number_format($week['expected'], 2) }}
                            </span>
                            <span class="tabular font-medium @if ($week['delta'] > 0) text-surplus dark:text-surplus-bright @elseif ($week['delta'] < 0) text-deficit dark:text-deficit-bright @else text-zinc-400 dark:text-zinc-500 @endif">
                                {{ $week['delta'] > 0 ? '+' : '' }}{{ number_format($week['delta'], 2) }}
                            </span>
                        </div>
                    @else
                        <span class="text-xs text-zinc-400 dark:text-zinc-500">Not started</span>
                    @endif
                </div>

                @if ($week['days']->isEmpty())
                    <p class="text-sm text-zinc-400 dark:text-zinc-500">No working days this week.</p>
                @endif

                <div class="grid grid-cols-2 gap-x-2 gap-y-3 sm:grid-cols-4 lg:grid-cols-7">
                    @foreach ($week['days'] as $day)
                        @php
                            $fraction = $scale > 0 ? min(1, abs($day['delta']) / $scale) : 0;
                        @endphp

                        <div wire:key="day-{{ $day['key'] }}" class="group/day flex flex-col">
                            <div class="flex items-baseline justify-between gap-1">
                                <div class="min-w-0 truncate">
                                    <span class="text-[0.7rem] font-medium uppercase tracking-[0.12em] {{ $day['isToday'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                                        {{ $day['date']->format('D') }}
                                    </span>
                                    <span class="tabular ms-1 text-xs {{ $day['isToday'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                                        {{ $day['date']->format('j M') }}
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    class="-me-1 shrink-0 rounded px-1 text-zinc-300 opacity-0 transition hover:text-zinc-700 focus-visible:opacity-100 group-hover/day:opacity-100 dark:text-zinc-600 dark:hover:text-white"
                                    wire:click="addSession('{{ $day['key'] }}')"
                                    aria-label="Add session on {{ $day['date']->format('l j F') }}"
                                >+</button>
                            </div>

                            <div @class([
                                'mt-1 rounded-md border px-2 py-1.5 transition',
                                'border-zinc-200 dark:border-zinc-700' => ! $day['isToday'] && ! $day['isFuture'],
                                'border-zinc-800 dark:border-white' => $day['isToday'],
                                'border-dashed border-zinc-200 dark:border-zinc-800' => $day['isFuture'],
                            ])>
                                @if ($day['bankHoliday'])
                                    <div class="truncate text-[0.7rem] text-ledger-ink/70 dark:text-zinc-400" title="{{ $day['bankHoliday'] }}">
                                        {{ $day['bankHoliday'] }}
                                    </div>
                                @endif

                                <div class="min-h-[1.25rem]">
                                    @forelse ($day['sessions'] as $session)
                                        <div class="group/entry flex items-center justify-between gap-1" wire:key="session-{{ $session->id }}">
                                            @if ($session->isRunning())
                                                <span class="tabular text-xs text-surplus dark:text-surplus-bright">
                                                    {{ $session->started_at->format('H:i') }} running
                                                </span>
                                            @else
                                                <button
                                                    type="button"
                                                    class="tabular truncate text-xs text-zinc-700 hover:underline dark:text-zinc-200"
                                                    wire:click="editSession({{ $session->id }})"
                                                >{{ $session->started_at->format('H:i') }}&ndash;{{ $session->ended_at->format('H:i') }}</button>

                                                <button
                                                    type="button"
                                                    class="shrink-0 text-xs text-zinc-300 opacity-0 transition hover:text-deficit focus-visible:opacity-100 group-hover/entry:opacity-100 dark:text-zinc-600"
                                                    wire:click="deleteSession({{ $session->id }})"
                                                    wire:confirm="Delete this session?"
                                                    aria-label="Delete session"
                                                >&times;</button>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-xs text-zinc-300 dark:text-zinc-600">&mdash;</span>
                                    @endforelse
                                </div>
                            </div>

                            {{-- Deviation from the zero line. --}}
                            <div class="relative mt-1.5 h-9" title="{{ number_format($day['worked'], 2) }} worked of {{ number_format($day['expected'], 2) }} expected">
                                <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-zinc-200 dark:bg-zinc-700"></div>

                                @unless ($day['isFuture'])
                                    @if ($day['delta'] != 0)
                                        <div
                                            class="absolute left-1/2 w-2.5 -translate-x-1/2 rounded-[1px] @if ($day['delta'] > 0) bottom-1/2 bg-surplus dark:bg-surplus-bright @else top-1/2 bg-deficit dark:bg-deficit-bright @endif"
                                            style="height: {{ max(2, round($fraction * 50)) }}%"
                                        ></div>
                                    @else
                                        <div class="absolute left-1/2 top-1/2 h-1 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-[1px] bg-zinc-300 dark:bg-zinc-600"></div>
                                    @endif
                                @endunless
                            </div>

                            <div class="flex items-baseline justify-between gap-1 text-[0.7rem]">
                                <span class="tabular text-zinc-400 dark:text-zinc-500">
                                    {{ number_format($day['worked'], 2) }} / {{ number_format($day['expected'], 2) }}
                                </span>

                                @if ($day['isFuture'])
                                    <span class="text-zinc-300 dark:text-zinc-600">&mdash;</span>
                                @else
                                    <span class="tabular font-medium @if ($day['delta'] > 0) text-surplus dark:text-surplus-bright @elseif ($day['delta'] < 0) text-deficit dark:text-deficit-bright @else text-zinc-400 dark:text-zinc-500 @endif">
                                        {{ $day['delta'] > 0 ? '+' : '' }}{{ number_format($day['delta'], 2) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
    @endif

    <flux:modal wire:model="showingForm" class="max-w-sm">
        <form wire:submit="saveSession" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit session' : 'Add session' }}</flux:heading>

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
