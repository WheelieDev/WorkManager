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

                <div @class([
                    'mt-1 rounded-md border px-2 py-1.5 transition',
                    'border-zinc-200 dark:border-zinc-700' => ! $d['isToday'] && ! $d['isFuture'],
                    'border-zinc-800 dark:border-white' => $d['isToday'],
                    'border-dashed border-zinc-200 dark:border-zinc-800' => $d['isFuture'],
                ])>
                    @if ($d['bankHoliday'])
                        <div class="truncate text-[0.7rem] text-ledger-ink/70 dark:text-zinc-400" title="{{ $d['bankHoliday'] }}">
                            {{ $d['bankHoliday'] }}
                        </div>
                    @endif

                    @if ($d['leave'] > 0)
                        <div class="truncate text-[0.7rem] text-zinc-500 dark:text-zinc-400" title="Annual leave">
                            Leave {{ number_format($d['leave'], 2) }}h
                        </div>
                    @endif

                    @if ($d['lieu'] > 0)
                        <div class="truncate text-[0.7rem] text-zinc-500 dark:text-zinc-400" title="Banked as time off in lieu">
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
                                            title="{{ $session->kind->label() }}"
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

                <div class="relative mt-1.5 h-9" title="{{ number_format($d['worked'], 2) }} worked of {{ number_format($d['expected'], 2) }} expected">
                    <div class="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-zinc-200 dark:bg-zinc-700"></div>

                    @unless ($d['isFuture'])
                        @if ($d['delta'] != 0)
                            <div class="absolute left-1/2 w-2.5 -translate-x-1/2 rounded-[1px] @if ($d['delta'] > 0) bottom-1/2 bg-surplus dark:bg-surplus-bright @else top-1/2 bg-deficit dark:bg-deficit-bright @endif"
                                 style="height: {{ max(2, round($fraction * 50)) }}%"></div>
                        @else
                            <div class="absolute left-1/2 top-1/2 h-1 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-[1px] bg-zinc-300 dark:bg-zinc-600"></div>
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
