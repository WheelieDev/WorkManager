{{-- A working week. Every day the pattern works, plus any day with something
     on it, each drawing its deviation from a shared zero line. --}}
<section class="mt-8">
    <div class="mb-2 flex items-baseline justify-between gap-3 border-b border-zinc-200 pb-1.5 dark:border-zinc-700">
        <span class="text-[0.7rem] font-medium uppercase tracking-[0.16em] text-zinc-400 dark:text-zinc-500">
            Week {{ $week['number'] }} of {{ $flexiPeriod->weekCount() }}
        </span>

        @if ($week['hasCountedDays'])
            <div class="flex items-baseline gap-2.5 text-xs">
                <span class="tabular text-zinc-400 dark:text-zinc-500">
                    {{ number_format($week['worked'], 2) }} / {{ number_format($week['expected'], 2) }}
                </span>
                <span class="tabular font-medium {{ $tone($week['delta']) }}">{{ $signed($week['delta']) }}</span>
            </div>
        @else
            <span class="text-xs text-zinc-400 dark:text-zinc-500">Not started</span>
        @endif
    </div>

    @php $visible = $week['days']->filter(fn (array $d): bool => $d['isVisible'])->values(); @endphp

    @if ($visible->isEmpty())
        <p class="text-sm text-zinc-400 dark:text-zinc-500">No working days this week.</p>
    @endif

    <div class="grid grid-cols-2 gap-x-2 gap-y-3 sm:grid-cols-4 lg:grid-cols-7">
        @foreach ($visible as $d)
            @php $fraction = $scale > 0 ? min(1, abs($d['delta']) / $scale) : 0; @endphp

            <div wire:key="day-{{ $d['key'] }}" class="group/day flex flex-col">
                <div class="flex items-baseline justify-between gap-1">
                    <button type="button"
                            class="min-w-0 truncate text-start hover:underline"
                            wire:click="showDay('{{ $d['key'] }}')"
                            title="Open {{ $d['date']->format('l j F') }}">
                        <span class="text-[0.7rem] font-medium uppercase tracking-[0.12em] {{ $d['isToday'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                            {{ $d['date']->format('D') }}
                        </span>
                        <span class="tabular ms-1 text-xs {{ $d['isToday'] ? 'text-zinc-800 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                            {{ $d['date']->format('j M') }}
                        </span>
                    </button>

                    <button type="button"
                            class="-me-1 shrink-0 rounded px-1 text-zinc-300 opacity-0 transition hover:text-zinc-700 focus-visible:opacity-100 group-hover/day:opacity-100 dark:text-zinc-600 dark:hover:text-white"
                            wire:click="addSession('{{ $d['key'] }}')"
                            aria-label="Add entry on {{ $d['date']->format('l j F') }}">+</button>
                </div>

                {{-- A bank holiday and an ordinary Tuesday were the same box
                     with different words in it. Each state now has a ground of
                     its own, always behind text that says the same thing. --}}
                <div @class([
                    'mt-1 rounded-md border px-2 py-1.5 transition',
                    'border-zinc-200 dark:border-zinc-700' => ! $d['isToday'] && ! $d['isFuture'],
                    'border-accent bg-accent/[0.07] dark:border-accent' => $d['isToday'],
                    'border-dashed border-zinc-200 dark:border-zinc-800' => $d['isFuture'],
                    'bg-pot-other/[0.08] dark:bg-pot-other-bright/[0.1]' => ($d['bankHoliday'] || ($d['phased'] > 0 && $d['leave'] <= 0)) && ! $d['isToday'],
                    'bg-pot-annual/[0.08] dark:bg-pot-annual-bright/[0.1]' => $d['leave'] > 0 && ! $d['bankHoliday'] && ! $d['isToday'],
                    'bg-pot-lieu/[0.08] dark:bg-pot-lieu-bright/[0.1]' => $d['lieu'] > 0 && $d['leave'] <= 0 && $d['phased'] <= 0 && ! $d['bankHoliday'] && ! $d['isToday'],
                ])>
                    @if ($d['bankHoliday'])
                        <div class="truncate text-[0.7rem] font-medium text-pot-other dark:text-pot-other-bright" title="{{ $d['bankHoliday'] }}">
                            {{ $d['bankHoliday'] }}
                        </div>
                    @endif

                    @if ($d['leave'] > 0)
                        <div class="truncate text-[0.7rem] font-medium text-pot-annual dark:text-pot-annual-bright" title="Annual leave">
                            Leave {{ number_format($d['leave'], 2) }}h
                        </div>
                    @endif

                    @if ($d['phased'] > 0)
                        <div class="truncate text-[0.7rem] font-medium text-pot-other dark:text-pot-other-bright" title="Phased return">
                            Phased {{ number_format($d['phased'], 2) }}h
                        </div>
                    @endif

                    @if ($d['lieu'] > 0)
                        <div class="truncate text-[0.7rem] font-medium text-pot-lieu dark:text-pot-lieu-bright" title="Banked as time off in lieu">
                            Lieu {{ number_format($d['lieu'], 2) }}h
                        </div>
                    @endif

                    <div class="min-h-[1.25rem]">
                        @forelse ($d['sessions'] as $session)
                            <div class="group/entry flex items-center justify-between gap-1" wire:key="session-{{ $session->id }}">
                                @if ($session->isRunning())
                                    <span class="tabular text-xs text-surplus dark:text-surplus-bright">
                                        {{ $session->started_at->format('H:i') }} running
                                    </span>
                                @else
                                    <button type="button"
                                            class="tabular truncate text-xs {{ $session->isLieu() ? 'text-zinc-400 dark:text-zinc-500' : 'text-zinc-700 dark:text-zinc-200' }} hover:underline"
                                            title="{{ collect([$session->kind->label(), $session->workArea?->name, $session->note])->filter()->join(' · ') }}"
                                            wire:click="editSession({{ $session->id }})">{{ $session->started_at->format('H:i') }}&ndash;{{ $session->ended_at->format('H:i') }}</button>

                                    <button type="button"
                                            class="shrink-0 text-xs text-zinc-300 opacity-0 transition hover:text-deficit focus-visible:opacity-100 group-hover/entry:opacity-100 dark:text-zinc-600"
                                            wire:click="deleteSession({{ $session->id }})"
                                            wire:confirm="Delete this entry?"
                                            aria-label="Delete entry">&times;</button>
                                @endif
                            </div>
                        @empty
                            <span class="text-xs text-zinc-300 dark:text-zinc-600">&mdash;</span>
                        @endforelse
                    </div>
                </div>

                {{-- Above the line is time banked, below it time owed, each
                     half tinted so the direction reads before the bar does. --}}
                <div class="relative mt-1.5 h-10 overflow-hidden rounded-md" title="{{ number_format($d['worked'], 2) }} worked of {{ number_format($d['expected'], 2) }} expected">
                    <div class="absolute inset-x-0 top-0 h-1/2 bg-surplus/[0.07] dark:bg-surplus-bright/[0.08]"></div>
                    <div class="absolute inset-x-0 bottom-0 h-1/2 bg-deficit/[0.07] dark:bg-deficit-bright/[0.08]"></div>

                    <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-zinc-300 dark:bg-zinc-600"></div>

                    @unless ($d['isFuture'])
                        @if ($d['delta'] != 0)
                            <div @class([
                                    'absolute left-1/2 w-5 -translate-x-1/2',
                                    'bottom-1/2 rounded-t-sm bg-gradient-to-t from-surplus/60 to-surplus dark:from-surplus-bright/60 dark:to-surplus-bright' => $d['delta'] > 0,
                                    'top-1/2 rounded-b-sm bg-gradient-to-b from-deficit/60 to-deficit dark:from-deficit-bright/60 dark:to-deficit-bright' => $d['delta'] < 0,
                                ])
                                 style="height: {{ max(2, round($fraction * 50)) }}%"></div>
                        @else
                            <div class="absolute left-1/2 top-1/2 h-1 w-5 -translate-x-1/2 -translate-y-1/2 rounded-sm bg-zinc-400 dark:bg-zinc-500"></div>
                        @endif
                    @endunless
                </div>

                <div class="flex items-baseline justify-between gap-1 text-[0.7rem]">
                    <span class="tabular text-zinc-400 dark:text-zinc-500">
                        {{ number_format($d['worked'], 2) }} / {{ number_format($d['expected'], 2) }}
                    </span>

                    @if ($d['isFuture'])
                        <span class="text-zinc-300 dark:text-zinc-600">&mdash;</span>
                    @else
                        <span class="tabular font-medium {{ $tone($d['delta']) }}">{{ $signed($d['delta']) }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>
