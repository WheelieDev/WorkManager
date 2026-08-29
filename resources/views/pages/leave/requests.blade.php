{{-- What a pot has actually had booked against it, as it was asked for.
     Rendered inside whichever pot is open, so the rows always belong to the
     figures above them. --}}
<section class="mt-8">
    <div class="flex items-baseline justify-between">
        <h2 class="text-[0.7rem] font-medium uppercase tracking-[0.18em] text-zinc-400 dark:text-zinc-500">
            {{ ['annual' => 'Booked leave', 'lieu' => 'Lieu taken back', 'other' => 'Booked absence', 'sickness' => 'Sickness in the rolling year'][$pot] ?? 'Booked leave' }}
        </h2>

        @if ($requests->isNotEmpty())
            <flux:text variant="subtle" class="text-sm">
                {{ $requests->count() }} {{ Str::plural('request', $requests->count()) }}
            </flux:text>
        @endif
    </div>

    @if ($requests->isEmpty())
        <p class="mt-2 border-t border-zinc-200 pt-3 text-sm text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
            No leave booked this year. Choose a first and last day and the hours come from your pattern.
        </p>
    @else
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-[0.7rem] uppercase tracking-[0.12em] text-zinc-400 dark:border-zinc-700 dark:text-zinc-500">
                        <th class="py-1.5 text-start font-medium">Dates</th>
                        @if ($pot === 'other')
                            <th class="py-1.5 text-start font-medium">Type</th>
                        @endif
                        <th class="py-1.5 text-end font-medium">Days</th>
                        <th class="py-1.5 text-end font-medium">Bank holidays</th>
                        <th class="py-1.5 text-end font-medium">Hours</th>
                        <th class="w-20"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($requests as $request)
                        @php
                            $holidays = $ledger->requestPublicHolidays($request);
                            $requestType = $request->days->first()?->type;
                        @endphp

                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="request-{{ $request->id }}">
                            <td class="tabular py-1.5 text-zinc-700 dark:text-zinc-200">
                                {{ $request->label() }}
                            </td>

                            @if ($pot === 'other')
                                <td class="py-1.5 text-zinc-500 dark:text-zinc-400">
                                    {{ $requestType?->label() ?? '—' }}
                                </td>
                            @endif

                            <td class="tabular py-1.5 text-end text-zinc-500 dark:text-zinc-400">
                                {{ $ledger->requestDays($request) }}
                            </td>

                            <td class="tabular py-1.5 text-end {{ $holidays > 0 ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-300 dark:text-zinc-600' }}">
                                {{ $holidays > 0 ? $holidays : '—' }}
                            </td>

                            <td class="tabular py-1.5 text-end text-zinc-700 dark:text-zinc-200">
                                {{ $hours($ledger->requestHours($request)) }}
                            </td>

                            <td class="py-1.5 text-end">
                                <div class="flex justify-end gap-1">
                                    <flux:button
                                        icon="pencil-square"
                                        size="xs"
                                        variant="subtle"
                                        inset="top bottom"
                                        wire:click="editLeave({{ $request->id }})"
                                        :aria-label="'Edit leave from '.$request->starts_on->format('j F Y')"
                                    />

                                    <flux:button
                                        icon="trash"
                                        size="xs"
                                        variant="subtle"
                                        inset="top bottom"
                                        wire:click="deleteLeave({{ $request->id }})"
                                        wire:confirm="Cancel this leave? The {{ $ledger->requestDays($request) }} {{ Str::plural('day', $ledger->requestDays($request)) }} it booked will be given back."
                                        :aria-label="'Cancel leave from '.$request->starts_on->format('j F Y')"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                <tfoot>
                    <tr class="font-medium">
                        <td class="py-1.5">Total</td>
                        <td class="tabular py-1.5 text-end">{{ $requests->sum(fn ($r) => $ledger->requestDays($r)) }}</td>
                        <td class="tabular py-1.5 text-end">{{ $requests->sum(fn ($r) => $ledger->requestPublicHolidays($r)) ?: '—' }}</td>
                        <td class="tabular py-1.5 text-end">{{ $hours($requests->sum(fn ($r) => $ledger->requestHours($r))) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</section>
