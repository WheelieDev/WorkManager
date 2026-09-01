{{-- One day, in full. Room to record what happened and, later, why a day was
     not worked. --}}
<section class="mt-8 max-w-2xl">
    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-zinc-200 pb-2 dark:border-zinc-700">
        <div class="flex items-baseline gap-3">
            <span class="text-[0.7rem] font-medium uppercase tracking-[0.16em] text-zinc-400 dark:text-zinc-500">
                Week {{ $flexiPeriod->weekNumberFor($day['date']) }} of {{ $flexiPeriod->weekCount() }}
            </span>

            @if ($day['isToday'])
                <span class="rounded-sm bg-zinc-800 px-1.5 py-0.5 text-[0.65rem] font-medium uppercase tracking-wider text-white dark:bg-white dark:text-zinc-900">Today</span>
            @endif

            @if ($day['bankHoliday'])
                <span class="text-xs text-ledger-ink/70 dark:text-zinc-400">{{ $day['bankHoliday'] }}</span>
            @endif

            @unless ($day['isTracked'])
                <span class="text-xs text-zinc-400 dark:text-zinc-500">Before tracking began</span>
            @endunless

            @if ($day['leave'] > 0)
                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                    Annual leave {{ $duration($day['leave']) }}
                </span>
            @endif

            @if ($day['phased'] > 0)
                <button type="button"
                        class="text-xs text-pot-other hover:underline dark:text-pot-other-bright"
                        wire:click="editPhasedReturn('{{ $day['key'] }}')"
                        title="Edit the phased return">
                    Phased return {{ $duration($day['phased']) }}
                </button>
            @endif

            {{-- Lieu is worked but not counted here: it is banked as leave, so
                 it sits beside the day's figures rather than inside them. --}}
            @if ($day['lieu'] > 0)
                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                    Lieu banked {{ $duration($day['lieu']) }}
                </span>
            @endif
        </div>

        <div class="flex items-baseline gap-3 text-sm">
            <span class="tabular text-zinc-400 dark:text-zinc-500">
                {{ $duration($day['worked']) }} / {{ $duration($day['expected']) }}
            </span>

            @if ($day['isFuture'] || ! $day['isTracked'])
                <span class="text-zinc-300 dark:text-zinc-600">&mdash;</span>
            @else
                <span class="tabular font-medium {{ $tone($day['delta']) }}">{{ $signed($day['delta']) }}</span>
            @endif
        </div>
    </div>

    {{-- The day's deviation, on the same scale the other views use. --}}
    @unless ($day['isFuture'] || ! $day['isTracked'])
        <div class="relative mt-3 h-5">
            <div class="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-zinc-300 dark:bg-zinc-600"></div>

            @if ($day['delta'] != 0)
                <div class="absolute top-1/2 h-2.5 -translate-y-1/2 rounded-[1px] @if ($day['delta'] > 0) left-1/2 bg-surplus dark:bg-surplus-bright @else right-1/2 bg-deficit dark:bg-deficit-bright @endif"
                     style="width: {{ max(0.5, min(1, abs($day['delta']) / $scale) * 50) }}%"></div>
            @else
                <div class="absolute left-1/2 top-1/2 h-2.5 w-1 -translate-x-1/2 -translate-y-1/2 rounded-[1px] bg-zinc-300 dark:bg-zinc-600"></div>
            @endif
        </div>
    @endunless

    <div class="mt-5 flex flex-col">
        @forelse ($day['sessions'] as $session)
            <div class="group/entry flex items-start justify-between gap-3 border-b border-zinc-100 py-2 dark:border-zinc-800" wire:key="entry-{{ $session->id }}">
                @if ($session->isRunning())
                    <span class="tabular text-sm text-surplus dark:text-surplus-bright">
                        {{ $session->started_at->format('H:i') }} &ndash; running
                        @if ($session->isLieu()) &middot; in lieu @endif
                    </span>
                    <span class="text-xs text-zinc-400 dark:text-zinc-500">Stop the timer on the dashboard</span>
                @else
                    {{-- The note sits under the times rather than beside them, so a
                         long one wraps into the row instead of squeezing the figures. --}}
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    class="tabular text-sm text-zinc-700 hover:underline dark:text-zinc-200"
                                    wire:click="editSession({{ $session->id }})">
                                {{ $session->started_at->format('H:i') }} &ndash; {{ $session->ended_at->format('H:i') }}
                            </button>

                            @if ($session->workArea)
                                <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $session->workArea->name }}</span>
                            @endif
                        </div>

                        @if ($session->note)
                            <p class="text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $session->note }}</p>
                        @endif
                    </div>

                    <div class="flex shrink-0 items-center gap-3 self-start">
                        @if ($session->isLieu())
                            <span class="text-[0.7rem] uppercase tracking-[0.12em] text-zinc-400 dark:text-zinc-500">In lieu</span>
                        @endif

                        <span class="tabular text-sm text-zinc-400 dark:text-zinc-500">{{ $duration($session->hours()) }}</span>
                        <button type="button"
                                class="text-sm text-zinc-300 opacity-0 transition hover:text-deficit focus-visible:opacity-100 group-hover/entry:opacity-100 dark:text-zinc-600"
                                wire:click="deleteSession({{ $session->id }})"
                                wire:confirm="Delete this entry?"
                                aria-label="Delete entry">&times;</button>
                    </div>
                @endif
            </div>
        @empty
            <p class="border-b border-zinc-100 py-3 text-sm text-zinc-400 dark:border-zinc-800 dark:text-zinc-500">
                Nothing recorded on this day.
            </p>
        @endforelse
    </div>

    <div class="mt-4 flex gap-2">
        <flux:button size="sm" variant="subtle" icon="plus" wire:click="addSession('{{ $day['key'] }}')">
            Add entry
        </flux:button>

        <flux:button size="sm" variant="subtle" icon="plus" wire:click="editPhasedReturn('{{ $day['key'] }}')">
            Phased return
        </flux:button>
    </div>
</section>
