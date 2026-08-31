<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'pages::login')
    ->middleware('guest')
    ->name('login');

Route::post('/logout', function (Request $request) {
    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

// The API documentation describes shapes, not data, so it stays open: a client
// generator on the command line has no PIN to offer.
Route::view('/docs', 'docs')->name('docs');
Route::get('/docs/openapi.yaml', fn () => response()->file(base_path('openapi.yaml'), [
    'Content-Type' => 'application/yaml',
]))->name('docs.spec');

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');

    Route::livewire('/timesheet', 'pages::timesheet')->name('timesheet');

    Route::livewire('/leave', 'pages::leave')->name('leave');

    Route::livewire('/settings', 'pages::settings')->name('settings');

    Route::livewire('/patterns', 'pages::patterns.index')->name('patterns.index');
    Route::livewire('/patterns/create', 'pages::patterns.form')->name('patterns.create');
    Route::livewire('/patterns/{pattern}/edit', 'pages::patterns.form')->name('patterns.edit');

    Route::livewire('/areas', 'pages::areas.index')->name('areas.index');
    Route::livewire('/areas/create', 'pages::areas.form')->name('areas.create');
    Route::livewire('/areas/{area}/edit', 'pages::areas.form')->name('areas.edit');
});
