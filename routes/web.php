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
    Route::view('/', 'welcome')->name('home');
});
