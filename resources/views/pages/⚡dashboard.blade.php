<?php

use App\Enums\WorkKind;
use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use App\Support\LeaveLedger;
use App\Support\LeaveYear;
use App\Support\LieuLedger;
use Livewire\Attributes\Session;
use Livewire\Component;

new class extends Component {
    /**
     * What the next session will be tracked as.
     *
     * Held across visits, because whoever has just worked an evening in lieu is
     * likely to be back tomorrow doing the same.
     */
    #[Session]
    public string $kind = WorkKind::Contracted->value;

    /**
     * Start the timer, tracking it as whichever kind is chosen.
     */
    public function startTimer(): void
    {
        if (WorkSession::current() !== null) {
            return;
        }

        WorkSession::start(kind: WorkKind::tryFrom($this->kind) ?? WorkKind::Contracted);
    }

    /**
     * Stop the running timer.
     *
     * Starting and stopping within the same second is a mis-click rather than
     * work, so the session is discarded instead of being saved as zero hours.
     */
    public function stopTimer(): void
    {
        $session = WorkSession::current();

        if ($session === null) {
            return;
        }

        if (now()->lessThanOrEqualTo($session->started_at)) {
            $session->delete();

            return;
        }

        $session->stop();
    }

    /**
     * Build today's figures and the period summary.
     */
    public function with(): array
    {
        $calculator = new FlexiCalculator;
        $period = FlexiPeriod::current();
        $balance = $calculator->balanceFor($period);
        $limit = FlexiPeriod::limit();

        $ledger = new LeaveLedger(LeaveYear::current());

        return [
            'kinds' => WorkKind::cases(),
            'ledger' => $ledger,
            'lieu' => new LieuLedger,
            'leaveToday' => $calculator->leaveHours(now()),
            'running' => WorkSession::current(),
            'workedToday' => $calculator->workedHours(now()),
            'lieuToday' => $calculator->lieuHours(now()),
            'expectedToday' => $calculator->expectedHours(now()),
            'deltaToday' => $calculator->deltaFor(now()),
            'bankHolidayToday' => $calculator->bankHolidayTitle(now()),
            'period' => $period,
            'balance' => $balance,
            'isOverLimit' => $calculator->isOverLimit($balance),
            'limit' => $limit,
            'progress' => min(100, (int) round(abs($balance) / $limit * 100)),
            'daysLeft' => max(0, (int) now()->startOfDay()->diffInDays($period->end->copy()->startOfDay(), false)),
        ];
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">Dashboard</flux:heading>
    <flux:text class="mt-2 mb-6">
        {{ now()->format('l j F Y') }}
        @if ($bankHolidayToday)
            &middot; <flux:badge size="sm" color="purple" inset="top bottom">{{ $bankHolidayToday }}</flux:badge>
        @endif
        @if ($leaveToday > 0)
            &middot; <flux:badge size="sm" color="zinc" inset="top bottom">On leave {{ number_format($leaveToday, 2) }}h</flux:badge>
        @endif
    </flux:text>

    <flux:separator variant="subtle" />

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        {{-- The timer and today's figures are one thing: the clock is what
             moves them, so they are read together rather than side by side in
             two boxes. --}}
        <flux:card class="lg:col-span-3">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-stretch sm:justify-between">
                <div class="min-w-0">
                    <flux:heading>Timer</flux:heading>

                    @if ($running)
                        <div
                            class="mt-4 font-mono text-4xl tabular-nums"
                            x-data="{
                                startedAt: {{ $running->started_at->getTimestamp() }},
                                now: Math.floor(Date.now() / 1000),
                                get elapsed() {
                                    const seconds = Math.max(0, this.now - this.startedAt);
                                    return [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
                                        .map((part) => String(part).padStart(2, '0'))
                                        .join(':');
                                },
                            }"
                            x-init="setInterval(() => now = Math.floor(Date.now() / 1000), 1000)"
                            x-text="elapsed"
                        >00:00:00</div>

                        <flux:text variant="subtle" class="mt-1">
                            Started at {{ $running->started_at->format('H:i') }}
                            &middot; {{ $running->kind->label() }}
                        </flux:text>

                        <flux:button variant="danger" icon="stop" class="mt-4" wire:click="stopTimer">
                            Stop
                        </flux:button>
                    @else
                        <div class="mt-4 font-mono text-4xl tabular-nums text-zinc-400 dark:text-zinc-500">00:00:00</div>

                        <flux:text variant="subtle" class="mt-1">Not running</flux:text>

                        {{-- Chosen before the timer runs, because what the hours are
                             for decides which pot they land in, not how they felt. --}}
                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <flux:button variant="primary" icon="play" wire:click="startTimer">
                                Start
                            </flux:button>

                            <flux:radio.group wire:model.live="kind" variant="segmented" size="sm">
                                @foreach ($kinds as $workKind)
                                    <flux:radio value="{{ $workKind->value }}" label="{{ $workKind->shortLabel() }}" />
                                @endforeach
                            </flux:radio.group>
                        </div>
                    @endif
                </div>

                <div class="border-t border-zinc-200 pt-5 sm:shrink-0 sm:border-s sm:border-t-0 sm:ps-6 sm:pt-0 dark:border-zinc-700">
                    <div class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">Today</div>

                    {{-- Three figures read as one sentence, so they sit on a
                         line rather than down a list the timer has to grow to
                         meet. --}}
                    <dl class="mt-3 flex flex-wrap gap-x-8 gap-y-3">
                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Worked</dt>
                            <dd class="tabular mt-0.5 text-lg font-medium text-zinc-800 dark:text-white">
                                {{ number_format($workedToday, 2) }}h
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Expected</dt>
                            <dd class="tabular mt-0.5 text-lg font-medium text-zinc-800 dark:text-white">
                                {{ number_format($expectedToday, 2) }}h
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500 dark:text-zinc-400">Difference</dt>
                            <dd class="tabular mt-0.5 text-lg font-medium {{ $deltaToday > 0 ? 'text-surplus dark:text-surplus-bright' : ($deltaToday < 0 ? 'text-deficit dark:text-deficit-bright' : 'text-zinc-800 dark:text-white') }}">
                                {{ $deltaToday > 0 ? '+' : '' }}{{ number_format($deltaToday, 2) }}h
                            </dd>
                        </div>

                        {{-- Lieu worked today moves none of the figures beside
                             it, so it is said plainly rather than left to be
                             missed. --}}
                        @if ($lieuToday > 0)
                            <div>
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">Banked in lieu</dt>
                                <dd class="tabular mt-0.5 text-lg font-medium text-zinc-800 dark:text-white">
                                    {{ number_format($lieuToday, 2) }}h
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>

            </div>
        </flux:card>

        <flux:card class="lg:col-span-3">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading>Flexi balance</flux:heading>
                    <flux:text variant="subtle" class="mt-1">
                        {{ $period->label() }} &middot; {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} left
                    </flux:text>
                </div>

                <div class="text-right">
                    <div @class([
                        'text-3xl font-semibold tabular-nums',
                        'text-lime-600 dark:text-lime-400' => $balance > 0,
                        'text-rose-600 dark:text-rose-400' => $balance < 0,
                    ])>
                        {{ $balance > 0 ? '+' : '' }}{{ number_format($balance, 2) }}h
                    </div>
                    <flux:text variant="subtle">of {{ $limit }}h allowed</flux:text>
                </div>
            </div>

            <flux:progress
                class="mt-4"
                :value="$progress"
                :color="$isOverLimit ? 'red' : ($balance < 0 ? 'rose' : 'lime')"
            />

            @if ($isOverLimit)
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4">
                    <flux:callout.text>
                        You are {{ number_format(abs($balance) - $limit, 2) }}h beyond the {{ $limit }}h limit.
                        @if ($balance > 0)
                            Only {{ $limit }}h will carry into the next period.
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <flux:button :href="route('timesheet')" variant="subtle" class="mt-4" wire:navigate>
                Open timesheet
            </flux:button>
        </flux:card>

        {{-- Leave sits beside the flexi balance but is kept plainly apart from
             it: the two pots never trade with each other. --}}
        <flux:card class="lg:col-span-3">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading>Leave</flux:heading>
                    <flux:text variant="subtle" class="mt-1">{{ $ledger->year->label() }}</flux:text>
                </div>

                <flux:button :href="route('leave')" variant="subtle" wire:navigate>Book leave</flux:button>
            </div>

            <div class="mt-4 flex items-baseline gap-2">
                <span class="tabular text-3xl font-semibold {{ $ledger->isOverdrawn() ? 'text-deficit dark:text-deficit-bright' : 'text-zinc-800 dark:text-white' }}">
                    {{ number_format($ledger->annualRemaining(), 2) }}
                </span>
                <flux:text variant="subtle">
                    of {{ number_format($ledger->annualEntitlement(), 2) }}h left
                    @if ($ledger->asDays($ledger->annualRemaining()) !== null)
                        &middot; about {{ $ledger->asDays($ledger->annualRemaining()) }} days
                    @endif
                </flux:text>
            </div>

            {{-- Lieu is banked by working it rather than awarded, so it is shown
                 as its own line rather than folded into the balance above. --}}
            <div class="mt-3 flex items-baseline gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                <flux:text>Time off in lieu</flux:text>
                <flux:text variant="strong" class="tabular-nums">{{ number_format($lieu->balance(), 2) }}h</flux:text>
                <flux:text variant="subtle">
                    banked
                    @if ($lieu->asDays($lieu->balance()) !== null && $lieu->balance() > 0)
                        &middot; about {{ $lieu->asDays($lieu->balance()) }} days
                    @endif
                </flux:text>
            </div>

            @if ($ledger->publicHolidayAdjustment() < 0)
                <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                    <flux:callout.text>
                        Bank holidays have used
                        {{ number_format($ledger->publicHolidayShortfall(), 2) }}h more than your allowance covers,
                        so that much bookable leave is making up the difference.
                    </flux:callout.text>
                </flux:callout>
            @elseif ($ledger->publicHolidayAdjustment() > 0)
                <flux:text variant="subtle" class="mt-3 block text-sm">
                    Includes {{ number_format($ledger->publicHolidayAdjustment(), 2) }}h of public holiday allowance
                    the bank holidays did not use.
                </flux:text>
            @endif
        </flux:card>
    </div>
</div>
