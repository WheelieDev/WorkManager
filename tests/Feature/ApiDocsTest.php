<?php

use App\Models\User;

it('is open without signing in, so a client generator can fetch the spec', function () {
    $this->get('/docs')->assertOk();
    $this->get('/docs/openapi.yaml')->assertOk();
});

it('renders Swagger UI pointed at the spec', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/docs')
        ->assertOk()
        ->assertSee('swagger-ui-bundle.js')
        ->assertSee(route('docs.spec'), escape: false);
});

it('serves the spec as YAML', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/docs/openapi.yaml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml');

    expect($response->baseResponse->getFile()->getPathname())->toBe(base_path('openapi.yaml'));
});

it('links to the documentation from the settings page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings'))->assertSee(route('docs'));
});
