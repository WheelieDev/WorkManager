<?php

use App\Enums\Weekday;
use App\Models\WorkPattern;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public ?WorkPattern $pattern = null;

    public string $start_date = '';

    /**
     * The submitted days, keyed by weekday value.
     *
     * @var array<int, array{worked: bool, start_time: string, end_time: string}>
     */
    public array $days = [];

    public string $default_start_time = '09:00';

    public string $default_end_time = '17:00';

    /**
     * The months of the year this pattern works.
     *
     * @var array<int, int>
     */
    public array $months = [];

    /**
     * Prime the form from an existing pattern, or with sensible defaults.
     */
    public function mount(?WorkPattern $pattern = null): void
    {
        $this->pattern = $pattern?->exists ? $pattern : null;

        $this->start_date = $this->pattern?->start_date->toDateString() ?? now()->toDateString();

        $this->months = array_map('intval', $this->pattern?->months ?: range(1, 12));

        $existing = $this->pattern?->days->keyBy(fn ($day) => $day->weekday->value);

        if ($this->pattern !== null) {
            $this->adoptDefaultsFrom($this->pattern);
        }

        foreach (Weekday::cases() as $weekday) {
            $day = $existing?->get($weekday->value);

            $this->days[$weekday->value] = [
                'worked' => $this->pattern === null ? true : (bool) $day?->isWorked(),
                'start_time' => $day?->start_time?->format('H:i') ?? $this->default_start_time,
                'end_time' => $day?->end_time?->format('H:i') ?? $this->default_end_time,
            ];
        }
    }

    /**
     * Take the default times from the hours the pattern already works most.
     */
    protected function adoptDefaultsFrom(WorkPattern $pattern): void
    {
        $worked = $pattern->days->filter(fn ($day): bool => $day->isWorked());

        if ($worked->isEmpty()) {
            return;
        }

        $mostCommon = $worked
            ->countBy(fn ($day): string => $day->start_time->format('H:i').'-'.$day->end_time->format('H:i'))
            ->sortDesc()
            ->keys()
            ->first();

        [$this->default_start_time, $this->default_end_time] = explode('-', (string) $mostCommon);
    }

    /**
     * Carry a changed default onto days still using the previous one.
     *
     * This runs before the property is written, so the current value is still
     * the default the days were given.
     */
    public function updatingDefaultStartTime(string $value): void
    {
        $this->spreadDefault('start_time', $value, $this->default_start_time);
    }

    /**
     * Carry a changed default onto days still using the previous one.
     */
    public function updatingDefaultEndTime(string $value): void
    {
        $this->spreadDefault('end_time', $value, $this->default_end_time);
    }

    /**
     * Put the default times onto every day that is switched on.
     */
    public function applyDefaultToAll(): void
    {
        foreach ($this->days as $value => $day) {
            if ($day['worked']) {
                $this->days[$value]['start_time'] = $this->default_start_time;
                $this->days[$value]['end_time'] = $this->default_end_time;
            }
        }
    }

    /**
     * Switching a day on gives it the default times.
     */
    public function updatedDays(mixed $value, string $key): void
    {
        [$weekday, $field] = array_pad(explode('.', $key), 2, null);

        if ($field !== 'worked' || ! $value) {
            return;
        }

        $this->days[$weekday]['start_time'] = $this->default_start_time;
        $this->days[$weekday]['end_time'] = $this->default_end_time;
    }

    /**
     * Update days that still hold the old default, leaving customised ones be.
     */
    protected function spreadDefault(string $field, string $value, string $previous): void
    {
        foreach ($this->days as $weekday => $day) {
            if ($day['worked'] && $day[$field] === $previous) {
                $this->days[$weekday][$field] = $value;
            }
        }
    }

    /**
     * Save the pattern and its days.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $pattern = $this->pattern ?? new WorkPattern;
        $pattern->start_date = $validated['start_date'];
        $pattern->months = array_values(array_map('intval', $validated['months']));
        $pattern->save();

        foreach (Weekday::cases() as $weekday) {
            $day = $validated['days'][$weekday->value];

            $pattern->days()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'start_time' => $day['worked'] ? $day['start_time'] : null,
                    'end_time' => $day['worked'] ? $day['end_time'] : null,
                ],
            );
        }

        $this->redirect(route('patterns.index'), navigate: true);
    }

    /**
     * Get the validation rules for the form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'start_date' => [
                'required',
                'date',
                Rule::unique('work_patterns', 'start_date')->ignore($this->pattern),
            ],
            'default_start_time' => ['required', 'date_format:H:i'],
            'default_end_time' => ['required', 'date_format:H:i', 'after:default_start_time'],
            'months' => ['required', 'array', 'min:1'],
            'months.*' => ['integer', 'between:1,12'],
            'days' => ['required', 'array'],
            'days.*.worked' => ['boolean'],
            'days.*.start_time' => ['nullable', 'required_if:days.*.worked,true', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'required_if:days.*.worked,true', 'date_format:H:i', 'after:days.*.start_time'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'start_date.unique' => 'A pattern already starts on that date.',
            'months.min' => 'Choose at least one month you work.',
            'months.required' => 'Choose at least one month you work.',
            'default_end_time.after' => 'The default end time must be after the default start time.',
            'days.*.start_time.required_if' => 'Enter a start time for this day.',
            'days.*.end_time.required_if' => 'Enter an end time for this day.',
            'days.*.end_time.after' => 'The end time must be after the start time.',
        ];
    }

    /**
     * Expose the weekdays to the view.
     */
    public function with(): array
    {
        return [
            'weekdays' => Weekday::cases(),
            'allMonths' => collect(range(1, 12))->mapWithKeys(fn (int $month): array => [
                $month => Carbon::create(null, $month)->format('M'),
            ]),
        ];
    }
}; ?>

<div class="max-w-xl">
    <flux:heading size="xl" level="1">
        {{ $pattern ? 'Edit pattern' : 'New pattern' }}
    </flux:heading>
    <flux:text class="mt-2">Set your usual hours once, then adjust any day that differs.</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:field>
            <flux:label>Start date</flux:label>
            <flux:description>The pattern applies from this date until a newer one starts.</flux:description>
            <flux:input type="date" wire:model="start_date" />
            <flux:error name="start_date" />
        </flux:field>

        <flux:field>
            <flux:label>Usual hours</flux:label>
            <flux:description>Days you switch on start with these times.</flux:description>

            <div class="flex flex-wrap items-center gap-3">
                <flux:input
                    type="time"
                    size="sm"
                    wire:model.live="default_start_time"
                    :label:sr="'Default start time'"
                />

                <flux:text class="shrink-0">to</flux:text>

                <flux:input
                    type="time"
                    size="sm"
                    wire:model.live="default_end_time"
                    :label:sr="'Default end time'"
                />

                <flux:button size="sm" variant="subtle" wire:click="applyDefaultToAll">
                    Apply to all days
                </flux:button>
            </div>

            <flux:error name="default_start_time" />
            <flux:error name="default_end_time" />
        </flux:field>

        <flux:field>
            <flux:label>Months worked</flux:label>
            <flux:description>Clear a month you never work, such as a summer break. Its days expect no hours.</flux:description>

            <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-6">
                @foreach ($allMonths as $number => $name)
                    <label class="cursor-pointer" wire:key="month-{{ $number }}">
                        <input type="checkbox" class="peer sr-only" value="{{ $number }}" wire:model="months" />
                        <span class="block rounded-md border border-zinc-200 py-1.5 text-center text-sm text-zinc-500 transition peer-checked:border-zinc-800 peer-checked:bg-zinc-800 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-zinc-400 dark:border-zinc-700 dark:text-zinc-400 dark:peer-checked:border-white dark:peer-checked:bg-white dark:peer-checked:text-zinc-900">
                            {{ $name }}
                        </span>
                    </label>
                @endforeach
            </div>

            <flux:error name="months" />
        </flux:field>

        <div class="flex flex-col gap-3">
            <flux:heading size="lg">Days worked</flux:heading>

            @foreach ($weekdays as $weekday)
                <div class="flex flex-col gap-1" wire:key="day-{{ $weekday->value }}">
                    <div class="flex items-center gap-3">
                        <flux:switch
                            wire:model.live="days.{{ $weekday->value }}.worked"
                            :label:sr="'Work '.$weekday->label()"
                        />

                        <flux:text class="w-24 shrink-0">{{ $weekday->label() }}</flux:text>

                        @if ($days[$weekday->value]['worked'])
                            <flux:input
                                type="time"
                                size="sm"
                                wire:model="days.{{ $weekday->value }}.start_time"
                                :label:sr="$weekday->label().' start time'"
                            />

                            <flux:text class="shrink-0">to</flux:text>

                            <flux:input
                                type="time"
                                size="sm"
                                wire:model="days.{{ $weekday->value }}.end_time"
                                :label:sr="$weekday->label().' end time'"
                            />
                        @else
                            <flux:text variant="subtle">Not worked</flux:text>
                        @endif
                    </div>

                    <flux:error name="days.{{ $weekday->value }}.start_time" />
                    <flux:error name="days.{{ $weekday->value }}.end_time" />
                </div>
            @endforeach
        </div>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">Save pattern</flux:button>
            <flux:button :href="route('patterns.index')" variant="ghost" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
