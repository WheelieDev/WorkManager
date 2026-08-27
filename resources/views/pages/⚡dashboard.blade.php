<?php

use Livewire\Component;

new class extends Component {
    //
}; ?>

<div>
    <flux:heading size="xl" level="1">Dashboard</flux:heading>
    <flux:text class="mt-2 mb-6">Welcome back, {{ auth()->user()->name }}.</flux:text>

    <flux:separator variant="subtle" />

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <flux:card>
            <flux:text>Open jobs</flux:text>
            <flux:heading size="xl" class="mt-2">0</flux:heading>
        </flux:card>

        <flux:card>
            <flux:text>Due this week</flux:text>
            <flux:heading size="xl" class="mt-2">0</flux:heading>
        </flux:card>

        <flux:card>
            <flux:text>Completed</flux:text>
            <flux:heading size="xl" class="mt-2">0</flux:heading>
        </flux:card>
    </div>
</div>
