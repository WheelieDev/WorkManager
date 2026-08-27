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

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');

    Route::livewire('/timesheet', 'pages::timesheet')->name('timesheet');

    Route::livewire('/patterns', 'pages::patterns.index')->name('patterns.index');
    Route::livewire('/patterns/create', 'pages::patterns.form')->name('patterns.create');
    Route::livewire('/patterns/{pattern}/edit', 'pages::patterns.form')->name('patterns.edit');
});
