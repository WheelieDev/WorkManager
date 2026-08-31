<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo('2026-08-29 10:00');

    partTimePattern();
});

it('leaves the appearance to the browser rather than forcing dark', function () {
    $html = $this->get(route('settings'))->getContent();

    expect($html)->toContain('<html lang="en">');
});

it('offers light, dark and following the system', function () {
    Livewire::test('pages::settings')
        ->assertSee('Appearance')
        ->assertSeeHtml('value="light"')
        ->assertSeeHtml('value="dark"')
        ->assertSeeHtml('value="system"');
});
