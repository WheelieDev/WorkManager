<?php

use App\Models\WorkSession;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Livewire\Component;

new class extends Component {
    /**
     * Start the timer.
     */
    public function startTimer(): void
    {
        if (WorkSession::current() !== null) {
            return;
        }

        WorkSession::start();
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

        return [
            'running' => WorkSession::current(),
            'workedToday' => $calculator->workedHours(now()),
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
    </flux:text>

    <flux:separator variant="subtle" />

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <flux:card class="lg:col-span-2">
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
                </flux:text>

                <flux:button variant="danger" icon="stop" class="mt-4" wire:click="stopTimer">
                    Stop
                </flux:button>
            @else
                <div class="mt-4 font-mono text-4xl tabular-nums text-zinc-400 dark:text-zinc-500">00:00:00</div>

                <flux:text variant="subtle" class="mt-1">Not running</flux:text>

                <flux:button variant="primary" icon="play" class="mt-4" wire:click="startTimer">
                    Start
                </flux:button>
            @endif
        </flux:card>

        <flux:card>
            <flux:heading>Today</flux:heading>

            <dl class="mt-4 flex flex-col gap-2">
                <div class="flex justify-between">
                    <dt><flux:text>Worked</flux:text></dt>
                    <dd><flux:text variant="strong">{{ number_format($workedToday, 2) }}h</flux:text></dd>
                </div>
                <div class="flex justify-between">
                    <dt><flux:text>Expected</flux:text></dt>
                    <dd><flux:text variant="strong">{{ number_format($expectedToday, 2) }}h</flux:text></dd>
                </div>
                <div class="flex justify-between">
                    <dt><flux:text>Difference</flux:text></dt>
                    <dd>
                        <flux:badge size="sm" :color="$deltaToday > 0 ? 'lime' : ($deltaToday < 0 ? 'rose' : 'zinc')">
                            {{ $deltaToday > 0 ? '+' : '' }}{{ number_format($deltaToday, 2) }}h
                        </flux:badge>
                    </dd>
                </div>
            </dl>
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
    </div>
</div>
