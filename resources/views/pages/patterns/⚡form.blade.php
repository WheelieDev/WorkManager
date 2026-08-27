<?php

use App\Enums\Weekday;
use App\Models\WorkPattern;
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

    /**
     * Prime the form from an existing pattern, or with sensible defaults.
     */
    public function mount(?WorkPattern $pattern = null): void
    {
        $this->pattern = $pattern?->exists ? $pattern : null;

        $this->start_date = $this->pattern?->start_date->toDateString() ?? now()->toDateString();

        $existing = $this->pattern?->days->keyBy(fn ($day) => $day->weekday->value);

        foreach (Weekday::cases() as $weekday) {
            $day = $existing?->get($weekday->value);

            $this->days[$weekday->value] = [
                'worked' => $this->pattern === null ? true : (bool) $day?->isWorked(),
                'start_time' => $day?->start_time?->format('H:i') ?? '09:00',
                'end_time' => $day?->end_time?->format('H:i') ?? '17:00',
            ];
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
        return ['weekdays' => Weekday::cases()];
    }
}; ?>

<div class="max-w-xl">
    <flux:heading size="xl" level="1">
        {{ $pattern ? 'Edit pattern' : 'New pattern' }}
    </flux:heading>
    <flux:text class="mt-2">Switch on the days you work, then set the hours for each.</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:field>
            <flux:label>Start date</flux:label>
            <flux:description>The pattern applies from this date until a newer one starts.</flux:description>
            <flux:input type="date" wire:model="start_date" />
            <flux:error name="start_date" />
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
