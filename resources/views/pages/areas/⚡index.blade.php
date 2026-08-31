<?php

use App\Models\WorkArea;
use App\Support\AreaTotals;
use Livewire\Component;

new class extends Component {
    /**
     * Delete an area, leaving the hours booked to it on the timesheet.
     */
    public function delete(WorkArea $area): void
    {
        $area->delete();
    }

    /**
     * Put an area away, or bring it back.
     */
    public function toggleArchived(WorkArea $area): void
    {
        $area->update([
            'archived_at' => $area->isArchived() ? null : now(),
        ]);
    }

    /**
     * Get the areas with the hours booked to each.
     */
    public function with(): array
    {
        return [
            'areas' => WorkArea::query()->orderBy('name')->get(),
            'totals' => new AreaTotals,
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Work areas</flux:heading>
            <flux:text class="mt-2">The strands of work you book time against. Hours are counted from the whole timesheet.</flux:text>
        </div>

        <flux:button :href="route('areas.create')" variant="primary" icon="plus" wire:navigate>
            New area
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    @if ($areas->isEmpty())
        <flux:callout icon="rectangle-stack">
            <flux:callout.heading>No areas yet</flux:callout.heading>
            <flux:callout.text>Add an area for each strand of work, then pick one when you record time.</flux:callout.text>
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column align="end">Hours booked</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($areas as $area)
                    <flux:table.row :key="$area->id">
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                {{ $area->name }}

                                @if ($area->isArchived())
                                    <flux:badge color="zinc" size="sm" inset="top bottom">Archived</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell align="end" variant="strong">
                            {{ number_format($totals->for($area), 2) }}
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />

                                <flux:menu>
                                    <flux:menu.item icon="pencil" :href="route('areas.edit', $area)" wire:navigate>
                                        Edit
                                    </flux:menu.item>

                                    <flux:menu.item
                                        :icon="$area->isArchived() ? 'arrow-uturn-left' : 'archive-box'"
                                        wire:click="toggleArchived({{ $area->id }})"
                                    >
                                        {{ $area->isArchived() ? 'Bring back' : 'Archive' }}
                                    </flux:menu.item>

                                    <flux:menu.item
                                        icon="trash"
                                        variant="danger"
                                        wire:click="delete({{ $area->id }})"
                                        wire:confirm="Delete {{ $area->name }}? Its hours stay on the timesheet but lose the label."
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
