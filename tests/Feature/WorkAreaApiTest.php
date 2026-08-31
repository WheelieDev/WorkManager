<?php

use App\Models\User;
use App\Models\WorkArea;
use Laravel\Sanctum\Sanctum;

it('returns 401 without a token', function () {
    $this->getJson('/api/work-areas')->assertUnauthorized();
});

it('lists active areas by name, leaving archived ones out', function () {
    Sanctum::actingAs(User::factory()->create());

    WorkArea::factory()->create(['name' => 'Renewals']);
    WorkArea::factory()->create(['name' => 'Licensing']);
    WorkArea::factory()->archived()->create(['name' => 'Archive']);

    $this->getJson('/api/work-areas')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Licensing')
        ->assertJsonPath('data.1.name', 'Renewals');
});
