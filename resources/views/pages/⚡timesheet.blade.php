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

        return [
            'weeks' => $weeks,
            'flexiPeriod' => $period,
            'balance' => $balance,
            'carry' => $calculator->carryInto($period),
            'isOverLimit' => $calculator->isOverLimit($balance),
            'limit' => FlexiPeriod::limit(),
        ];
    }
}; ?>

<div>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <flux:heading size="xl" level="1">Timesheet</flux:heading>

            <flux:badge size="sm" :color="$balance > 0 ? 'lime' : ($balance < 0 ? 'rose' : 'zinc')">
                Balance {{ $balance > 0 ? '+' : '' }}{{ number_format($balance, 2) }}h
            </flux:badge>

            @if ($isOverLimit)
                <flux:badge size="sm" color="red" icon="exclamation-triangle">
                    Over the {{ $limit }}h limit
                </flux:badge>
            @endif
        </div>

        <div class="flex items-center gap-1">
            <flux:button icon="chevron-left" size="sm" variant="subtle" wire:click="shiftPeriod(-1)" aria-label="Previous period" />
            <flux:button size="sm" variant="subtle" wire:click="thisPeriod">This period</flux:button>
            <flux:button icon="chevron-right" size="sm" variant="subtle" wire:click="shiftPeriod(1)" aria-label="Next period" />

            <flux:switch wire:model.live="allDays" :label="'All days'" class="ms-2" />
        </div>
    </div>

    <flux:text variant="subtle" class="mt-1 text-sm">
        Period {{ $flexiPeriod->label() }}@if ($carry != 0) &middot; includes {{ $carry > 0 ? '+' : '' }}{{ number_format($carry, 2) }}h carried in @endif
    </flux:text>

    <div class="mt-4 flex flex-col gap-3">
        @foreach ($weeks as $week)
            <section wire:key="week-{{ $week['start']->toDateString() }}">
                <div class="mb-1 flex items-baseline justify-between gap-2">
                    <div class="flex items-baseline gap-2">
                        <span @class([
                            'text-sm font-medium',
                            'text-zinc-800 dark:text-white' => $week['isCurrent'],
                            'text-zinc-500 dark:text-white/60' => ! $week['isCurrent'],
                        ])>Week {{ $week['number'] }}</span>

                        <span class="text-xs text-zinc-400 dark:text-white/40">
                            {{ $week['start']->format('j M') }}
                        </span>

                        @if ($week['isCurrent'])
                            <flux:badge size="sm" color="blue" inset="top bottom">This week</flux:badge>
                        @endif
                    </div>

                    @if ($week['hasCountedDays'])
                        <div class="flex items-baseline gap-2 text-xs tabular-nums">
                            <span class="text-zinc-500 dark:text-white/60">
                                {{ number_format($week['worked'], 2) }} / {{ number_format($week['expected'], 2) }}
                            </span>
                            <span @class([
                                'font-medium',
                                'text-lime-600 dark:text-lime-400' => $week['delta'] > 0,
                                'text-rose-600 dark:text-rose-400' => $week['delta'] < 0,
                                'text-zinc-400 dark:text-white/40' => $week['delta'] == 0,
                            ])>{{ $week['delta'] > 0 ? '+' : '' }}{{ number_format($week['delta'], 2) }}</span>
                        </div>
                    @else
                        <span class="text-xs text-zinc-400 dark:text-white/40">Not started</span>
                    @endif
                </div>

                @if ($week['days']->isEmpty())
                    <flux:text variant="subtle" class="text-sm">No working days this week.</flux:text>
                @endif

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7">
                    @foreach ($week['days'] as $day)
                        <div
                            wire:key="day-{{ $day['key'] }}"
                            @class([
                                'rounded-lg border px-2.5 py-2 flex flex-col gap-1',
                                'border-zinc-200 dark:border-zinc-700' => ! $day['isToday'],
                                'border-blue-500 dark:border-blue-400' => $day['isToday'],
                                'opacity-60' => $day['isFuture'],
                            ])
                        >
                            <div class="flex items-baseline justify-between gap-1">
                                <div class="min-w-0 truncate">
                                    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $day['date']->format('D') }}</span>
                                    <span class="text-xs text-zinc-500 dark:text-white/60">{{ $day['date']->format('j M') }}</span>
                                </div>

                                <flux:button
                                    icon="plus"
                                    size="xs"
                                    variant="subtle"
                                    inset="top bottom"
                                    wire:click="addSession('{{ $day['key'] }}')"
                                    :aria-label="'Add session on '.$day['date']->format('l j F')"
                                />
                            </div>

                            @if ($day['bankHoliday'])
                                <div class="truncate text-xs text-purple-600 dark:text-purple-300" title="{{ $day['bankHoliday'] }}">
                                    {{ $day['bankHoliday'] }}
                                </div>
                            @endif

                            @forelse ($day['sessions'] as $session)
                                <div class="group flex items-center justify-between gap-1" wire:key="session-{{ $session->id }}">
                                    @if ($session->isRunning())
                                        <span class="text-xs text-amber-600 dark:text-amber-400">
                                            {{ $session->started_at->format('H:i') }} running
                                        </span>
                                    @else
                                        <button
                                            type="button"
                                            class="truncate text-xs tabular-nums text-zinc-700 hover:underline dark:text-zinc-300"
                                            wire:click="editSession({{ $session->id }})"
                                        >
                                            {{ $session->started_at->format('H:i') }}&ndash;{{ $session->ended_at->format('H:i') }}
                                        </button>

                                        <button
                                            type="button"
                                            class="shrink-0 text-xs text-zinc-400 opacity-0 transition hover:text-rose-600 focus-visible:opacity-100 group-hover:opacity-100 dark:hover:text-rose-400"
                                            wire:click="deleteSession({{ $session->id }})"
                                            wire:confirm="Delete this session?"
                                            aria-label="Delete session"
                                        >&times;</button>
                                    @endif
                                </div>
                            @empty
                                <div class="text-xs text-zinc-400 dark:text-white/40">&mdash;</div>
                            @endforelse

                            <div class="mt-auto flex items-baseline justify-between gap-1 border-t border-zinc-100 pt-1 text-xs tabular-nums dark:border-zinc-800">
                                <span class="text-zinc-500 dark:text-white/60">
                                    {{ number_format($day['worked'], 2) }} / {{ number_format($day['expected'], 2) }}
                                </span>

                                @if ($day['isFuture'])
                                    <span class="text-zinc-400 dark:text-white/40">&mdash;</span>
                                @else
                                    <span @class([
                                        'font-medium',
                                        'text-lime-600 dark:text-lime-400' => $day['delta'] > 0,
                                        'text-rose-600 dark:text-rose-400' => $day['delta'] < 0,
                                        'text-zinc-400 dark:text-white/40' => $day['delta'] == 0,
                                    ])>
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
