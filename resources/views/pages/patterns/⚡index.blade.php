<?php

use App\Models\WorkPattern;
use Livewire\Component;

new class extends Component {
    /**
     * Delete a pattern along with its days.
     */
    public function delete(WorkPattern $pattern): void
    {
        $pattern->delete();

        $this->dispatch('pattern-deleted');
    }

    /**
     * Get the patterns, newest start date first.
     */
    public function with(): array
    {
        return [
            'patterns' => WorkPattern::query()
                ->with('days')
                ->orderByDesc('start_date')
                ->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Working patterns</flux:heading>
            <flux:text class="mt-2">The pattern with the newest start date is the one in effect.</flux:text>
        </div>

        <flux:button :href="route('patterns.create')" variant="primary" icon="plus" wire:navigate>
            New pattern
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($patterns->isEmpty())
        <flux:callout icon="calendar-days">
            <flux:callout.heading>No patterns yet</flux:callout.heading>
            <flux:callout.text>Add a pattern with a start date to record the hours you work each week.</flux:callout.text>
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Start date</flux:table.column>
                <flux:table.column>Week</flux:table.column>
                <flux:table.column>Months</flux:table.column>
                <flux:table.column align="end">Weekly hours</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($patterns as $pattern)
                    <flux:table.row :key="$pattern->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                {{ $pattern->start_date->format('j M Y') }}

                                @if ($pattern->isCurrent())
                                    <flux:badge color="lime" size="sm" inset="top bottom">Current</flux:badge>
                                @elseif ($pattern->start_date->isFuture())
                                    <flux:badge color="zinc" size="sm" inset="top bottom">Upcoming</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($pattern->days as $day)
                                    <flux:badge
                                        size="sm"
                                        :color="$day->isWorked() ? 'blue' : 'zinc'"
                                        :title="$day->isWorked() ? $day->start_time->format('H:i').' to '.$day->end_time->format('H:i') : 'Not worked'"
                                    >
                                        {{ $day->weekday->abbreviation() }}
                                    </flux:badge>
                                @endforeach
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @php $off = $pattern->monthsOff(); @endphp

                            @if ($off === [])
                                <flux:text variant="subtle" class="text-sm">All year</flux:text>
                            @else
                                <flux:text variant="subtle" class="text-sm">
                                    Off in {{ collect($off)->map(fn (int $m): string => now()->setMonth($m)->format('M'))->join(', ', ' and ') }}
                                </flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end" variant="strong">{{ $pattern->weeklyHours() }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil" :href="route('patterns.edit', $pattern)" wire:navigate>
                                        Edit
                                    </flux:menu.item>

                                    <flux:menu.item
                                        icon="trash"
                                        variant="danger"
                                        wire:click="delete({{ $pattern->id }})"
                                        wire:confirm="Delete the pattern starting {{ $pattern->start_date->format('j M Y') }}?"
                                    >
                                        Delete
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
