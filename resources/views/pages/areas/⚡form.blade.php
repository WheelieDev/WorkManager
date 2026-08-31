<?php

use App\Models\WorkArea;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component {
    public ?WorkArea $area = null;

    public string $name = '';

    /**
     * Prime the form from an existing area, or leave it empty for a new one.
     */
    public function mount(?WorkArea $area = null): void
    {
        $this->area = $area?->exists ? $area : null;

        $this->name = $this->area?->name ?? '';
    }

    /**
     * Save the area.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $area = $this->area ?? new WorkArea;
        $area->name = $validated['name'];
        $area->save();

        $this->redirect(route('areas.index'), navigate: true);
    }

    /**
     * Get the validation rules for the form.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('work_areas', 'name')->ignore($this->area),
            ],
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
            'name.unique' => 'An area already goes by that name.',
        ];
    }
}; ?>

<div class="max-w-xl">
    <flux:heading size="xl" level="1">
        {{ $area ? 'Edit area' : 'New area' }}
    </flux:heading>
    <flux:text class="mt-2">Name a strand of work you want to see the hours for.</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:field>
            <flux:label>Name</flux:label>
            <flux:description>How it appears when you pick it on an entry.</flux:description>
            <flux:input wire:model="name" />
            <flux:error name="name" />
        </flux:field>

        <div class="flex gap-3">
            <flux:button type="submit" variant="primary">Save area</flux:button>
            <flux:button :href="route('areas.index')" variant="ghost" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
