<?php

use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    /**
     * The Monday of the week being viewed.
     */
    #[Url]
    public string $week = '';

    public ?int $editingId = null;

    public string $date = '';

    public string $start_time = '';

    public string $end_time = '';

    public bool $showingForm = false;

    /**
     * Default to the week containing today.
     */
    public function mount(): void
    {
        if ($this->week === '') {
            $this->week = now()->startOfWeek()->toDateString();
        }
    }

    /**
     * Get the Monday of the week being viewed.
     */
    public function weekStart(): Carbon
    {
        return Carbon::parse($this->week)->startOfWeek();
    }

    /**
     * Move to the previous or next week.
     */
    public function shiftWeek(int $weeks): void
    {
        $this->week = $this->weekStart()->addWeeks($weeks)->toDateString();
    }

    /**
     * Jump back to the current week.
     */
    public function thisWeek(): void
    {
        $this->week = now()->startOfWeek()->toDateString();
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
     * Build the week, the day totals and the period summary.
     */
    public function with(): array
    {
        $calculator = new FlexiCalculator;
        $start = $this->weekStart();
        $period = FlexiPeriod::forDate($start);

        $sessions = WorkSession::query()
            ->whereBetween('started_at', [$start->copy()->startOfDay(), $start->copy()->addDays(6)->endOfDay()])
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (WorkSession $session): string => $session->date()->toDateString());

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
            ];
        });

        $balance = $calculator->balanceFor($period);

        return [
            'days' => $days,
            'period' => $period,
            'weekNumber' => $period->weekNumberFor($start),
            'balance' => $balance,
            'carry' => $calculator->carryInto($period),
            'isOverLimit' => $calculator->isOverLimit($balance),
            'limit' => FlexiPeriod::limit(),
        ];
    }
}; ?>

<div>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Timesheet</flux:heading>
            <flux:text class="mt-2">
                Week {{ $weekNumber }} of {{ $period->weekCount() }} &middot; period {{ $period->label() }}
            </flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:button icon="chevron-left" size="sm" wire:click="shiftWeek(-1)" aria-label="Previous week" />
            <flux:button size="sm" wire:click="thisWeek">This week</flux:button>
            <flux:button icon="chevron-right" size="sm" wire:click="shiftWeek(1)" aria-label="Next week" />
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <flux:badge size="lg" :color="$balance > 0 ? 'lime' : ($balance < 0 ? 'rose' : 'zinc')">
            Balance {{ $balance > 0 ? '+' : '' }}{{ number_format($balance, 2) }}h
        </flux:badge>

        @if ($carry != 0)
            <flux:text variant="subtle">
                includes {{ $carry > 0 ? '+' : '' }}{{ number_format($carry, 2) }}h carried in
            </flux:text>
        @endif

        @if ($isOverLimit)
            <flux:badge size="lg" color="red" icon="exclamation-triangle">
                Over the {{ $limit }}h limit
            </flux:badge>
        @endif
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-3 lg:grid-cols-7">
        @foreach ($days as $day)
            <div
                wire:key="day-{{ $day['key'] }}"
                @class([
                    'rounded-lg border p-3 flex flex-col gap-2',
                    'border-zinc-200 dark:border-zinc-700' => ! $day['isToday'],
                    'border-blue-500 dark:border-blue-400' => $day['isToday'],
                    'opacity-60' => $day['isFuture'],
                ])
            >
                <div>
                    <flux:text variant="strong">{{ $day['date']->format('D') }}</flux:text>
                    <flux:text variant="subtle" class="text-sm">{{ $day['date']->format('j M') }}</flux:text>

                    @if ($day['bankHoliday'])
                        <flux:badge size="sm" color="purple" class="mt-1">{{ $day['bankHoliday'] }}</flux:badge>
                    @endif
                </div>

                <div class="flex flex-col gap-1">
                    @forelse ($day['sessions'] as $session)
                        <div class="flex items-center justify-between gap-1" wire:key="session-{{ $session->id }}">
                            @if ($session->isRunning())
                                <flux:badge size="sm" color="amber">
                                    {{ $session->started_at->format('H:i') }} running
                                </flux:badge>
                            @else
                                <button
                                    type="button"
                                    class="text-sm text-zinc-700 hover:underline dark:text-zinc-300"
                                    wire:click="editSession({{ $session->id }})"
                                >
                                    {{ $session->started_at->format('H:i') }}&ndash;{{ $session->ended_at->format('H:i') }}
                                </button>

                                <flux:button
                                    icon="x-mark"
                                    size="xs"
                                    variant="subtle"
                                    wire:click="deleteSession({{ $session->id }})"
                                    wire:confirm="Delete this session?"
                                    aria-label="Delete session"
                                />
                            @endif
                        </div>
                    @empty
                        <flux:text variant="subtle" class="text-sm">&mdash;</flux:text>
                    @endforelse
                </div>

                <flux:button size="xs" variant="subtle" icon="plus" wire:click="addSession('{{ $day['key'] }}')">
                    Add
                </flux:button>

                <flux:separator variant="subtle" />

                <dl class="text-sm">
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">Worked</dt>
                        <dd>{{ number_format($day['worked'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-zinc-500 dark:text-zinc-400">Expected</dt>
                        <dd>{{ number_format($day['expected'], 2) }}</dd>
                    </div>
                    <div class="flex justify-between font-medium">
                        <dt class="text-zinc-500 dark:text-zinc-400">Delta</dt>
                        <dd @class([
                            'text-lime-600 dark:text-lime-400' => $day['delta'] > 0,
                            'text-rose-600 dark:text-rose-400' => $day['delta'] < 0,
                        ])>
                            {{ $day['delta'] > 0 ? '+' : '' }}{{ number_format($day['delta'], 2) }}
                        </dd>
                    </div>
                </dl>
            </div>
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
