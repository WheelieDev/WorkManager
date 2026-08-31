{{-- The whole period: the same scale turned on its side, one bar a week
     running left or right of a single zero axis. --}}
<section class="mt-8">
    <div class="mb-2 flex items-baseline justify-between border-b border-zinc-200 pb-1.5 text-[0.7rem] font-medium uppercase tracking-[0.16em] text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
        <span>Week</span>
        <span>Worked / expected &middot; delta</span>
    </div>

    <div class="flex flex-col">
        @foreach ($weeks as $w)
            @php
                $fraction = $w['hasCountedDays'] && $weekScale > 0 ? min(1, abs($w['delta']) / $weekScale) : 0;
            @endphp

            <button
                type="button"
                wire:key="weekbar-{{ $w['start']->toDateString() }}"
                wire:click="showWeek('{{ $w['start']->toDateString() }}')"
                @class([
                    'flex items-center gap-3 border-b border-zinc-100 py-2 text-start transition hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-white/5',
                    'opacity-50' => ! $w['hasCountedDays'],
                ])
                title="Open week {{ $w['number'] }}"
            >
                <div class="flex w-28 shrink-0 items-baseline gap-2">
                    <span class="text-[0.7rem] font-medium uppercase tracking-[0.16em] {{ $w['isCurrent'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                        Week {{ $w['number'] }}
                    </span>
                    <span class="tabular text-xs text-zinc-400 dark:text-zinc-500">{{ $w['start']->format('j M') }}</span>
                </div>

                {{-- The two halves are grounded in their own tint, so which
                     way a week leans is legible before any bar is drawn and
                     the chart carries colour even where the deltas are small. --}}
                <div class="relative h-7 flex-1 overflow-hidden rounded-md">
                    <div class="absolute inset-y-0 left-0 w-1/2 bg-deficit/[0.07] dark:bg-deficit-bright/[0.08]"></div>
                    <div class="absolute inset-y-0 right-0 w-1/2 bg-surplus/[0.07] dark:bg-surplus-bright/[0.08]"></div>

                    <div class="absolute left-1/2 top-0 h-full w-px -translate-x-1/2 bg-zinc-300 dark:bg-zinc-600"></div>

                    @if ($w['hasCountedDays'])
                        @if ($w['delta'] != 0)
                            {{-- The fill deepens away from the axis, so a long
                                 week reads as heavier and not merely longer. --}}
                            <div @class([
                                    'absolute top-1/2 h-5 -translate-y-1/2',
                                    'left-1/2 rounded-e-sm bg-gradient-to-r from-surplus/60 to-surplus dark:from-surplus-bright/60 dark:to-surplus-bright' => $w['delta'] > 0,
                                    'right-1/2 rounded-s-sm bg-gradient-to-l from-deficit/60 to-deficit dark:from-deficit-bright/60 dark:to-deficit-bright' => $w['delta'] < 0,
                                ])
                                 style="width: {{ max(0.5, $fraction * 50) }}%"></div>
                        @else
                            <div class="absolute left-1/2 top-1/2 h-5 w-1 -translate-x-1/2 -translate-y-1/2 rounded-sm bg-zinc-400 dark:bg-zinc-500"></div>
                        @endif
                    @endif

                    @if ($w['isCurrent'])
                        <span class="absolute right-0 top-1/2 -translate-y-1/2 rounded-sm bg-zinc-800 px-1.5 py-0.5 text-[0.65rem] font-medium uppercase tracking-wider text-white dark:bg-white dark:text-zinc-900">This week</span>
                    @endif
                </div>

                <div class="flex w-40 shrink-0 items-baseline justify-end gap-2.5 text-xs">
                    @if ($w['hasCountedDays'])
                        <span class="tabular text-zinc-400 dark:text-zinc-500">
                            {{ number_format($w['worked'], 2) }} / {{ number_format($w['expected'], 2) }}
                        </span>
                        <span class="tabular w-14 text-right font-medium {{ $tone($w['delta']) }}">{{ $signed($w['delta']) }}</span>
                    @else
                        <span class="text-zinc-400 dark:text-zinc-500">Not started</span>
                    @endif
                </div>
            </button>
        @endforeach
    </div>

    <p class="mt-3 text-sm text-zinc-400 dark:text-zinc-500">Choose a week to see its days.</p>
</section>
