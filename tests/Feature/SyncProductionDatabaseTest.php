<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // The guards are what these tests are about, so the command has to think
    // it is on a development machine, with a marker it cannot use to change
    // which database this machine is really pointed at.
    app()->detectEnvironment(fn () => 'local');

    $this->marker = storage_path('framework/testing/using-sandbox-database');

    File::ensureDirectoryExists(dirname($this->marker));
    File::delete($this->marker);

    // The guard only reads the driver out of the configuration, so saying
    // mysql here is enough to get past it without moving the test suite off
    // the in-memory database it is running inside.
    $this->connection = config('database.default');

    config([
        'database.sandbox_marker' => $this->marker,
        'database.production_url' => 'mysql://user:secret@db.example.com:3306/workmanager',
        "database.connections.{$this->connection}.driver" => 'mysql',
    ]);
});

afterEach(function () {
    File::delete($this->marker);
});

it('refuses to run anywhere but a local environment', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:sync')
        ->expectsOutputToContain('only runs in a local environment')
        ->assertFailed();
})->note('It drops every local table, so it must never reach a deployed machine.');

it('refuses to run while the sandbox is in use', function () {
    File::put($this->marker, '');

    $this->artisan('db:sync')
        ->expectsOutputToContain('The sandbox is in use')
        ->assertFailed();
})->note('Otherwise the copy of production lands in the throwaway database.');

it('refuses to import into a connection that is not mysql', function () {
    config(["database.connections.{$this->connection}.driver" => 'sqlite']);

    $this->artisan('db:sync')
        ->expectsOutputToContain('can only be imported into mysql')
        ->assertFailed();
});

it('reports when no production database is configured', function () {
    config(['database.production_url' => null]);

    $this->artisan('db:sync')
        ->expectsOutputToContain('Set DB_PRODUCTION_URL')
        ->assertFailed();
});

it('rejects a production url of the wrong scheme', function () {
    config(['database.production_url' => 'postgres://user:secret@db.example.com:5432/workmanager']);

    $this->artisan('db:sync')
        ->expectsOutputToContain('must be a mysql:// or mariadb:// URL')
        ->assertFailed();
});

it('rejects a production url with no database on it', function () {
    config(['database.production_url' => 'mysql://user:secret@db.example.com:3306']);

    $this->artisan('db:sync')
        ->expectsOutputToContain('must name a database')
        ->assertFailed();
});

it('leaves the dumps out of the repository', function () {
    expect(File::get(base_path('database/.gitignore')))->toContain('/dumps');
})->note('A dump is a copy of live hours, and belongs on one machine only.');
