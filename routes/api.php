<?php

use App\Http\Controllers\Api\HoursController;
use App\Http\Controllers\Api\TimerController;
use App\Http\Controllers\Api\TimerNoteController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WorkAreaController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/tokens', [TokenController::class, 'store'])->name('api.tokens.store');

Route::middleware('auth:sanctum')->group(function () {
    Route::delete('/auth/tokens/current', [TokenController::class, 'destroy'])->name('api.tokens.destroy');

    Route::get('/timer', [TimerController::class, 'show'])->name('api.timer.show');
    Route::patch('/timer', [TimerController::class, 'update'])->name('api.timer.update');
    Route::delete('/timer', [TimerController::class, 'destroy'])->name('api.timer.destroy');
    Route::post('/timer/start', [TimerController::class, 'start'])->name('api.timer.start');
    Route::post('/timer/stop', [TimerController::class, 'stop'])->name('api.timer.stop');
    Route::post('/timer/notes', [TimerNoteController::class, 'store'])->name('api.timer.notes.store');

    Route::get('/work-areas', [WorkAreaController::class, 'index'])->name('api.work-areas.index');

    Route::get('/hours', [HoursController::class, 'show'])->name('api.hours.show');
});
