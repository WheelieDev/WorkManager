<?php

use App\Models\WorkSession;

it('starts a session running', function () {
    $this->travelTo('2026-08-27 09:00');

    $session = WorkSession::start();

    expect($session->isRunning())->toBeTrue()
        ->and($session->ended_at)->toBeNull()
        ->and($session->started_at->format('H:i'))->toBe('09:00');
});

it('refuses to start a second session while one is running', function () {
    WorkSession::start();

    expect(fn () => WorkSession::start())
        ->toThrow(RuntimeException::class, 'A work session is already running.');

    expect(WorkSession::query()->count())->toBe(1);
});

it('starts again once the running session is stopped', function () {
    $this->travelTo('2026-08-27 09:00');
    $first = WorkSession::start();

    $this->travelTo('2026-08-27 12:00');
    $first->stop();

    $this->travelTo('2026-08-27 13:00');
    WorkSession::start();

    expect(WorkSession::query()->count())->toBe(2);
});

it('stops a running session and records the hours', function () {
    $this->travelTo('2026-08-27 09:00');
    $session = WorkSession::start();

    $this->travelTo('2026-08-27 12:30');
    $session->stop();

    expect($session->isRunning())->toBeFalse()
        ->and($session->hours())->toBe(3.5);
});

it('refuses to stop a session twice', function () {
    $session = WorkSession::factory()->on('2026-08-27')->create();

    expect(fn () => $session->stop())
        ->toThrow(RuntimeException::class, 'This work session has already been stopped.');
});

it('refuses to end a session before it started', function () {
    $this->travelTo('2026-08-27 12:00');
    $session = WorkSession::start();

    expect(fn () => $session->stop(now()->parse('2026-08-27 11:00')))
        ->toThrow(RuntimeException::class, 'A work session must end after it starts.');
});

it('reports no hours while still running', function () {
    $this->travelTo('2026-08-27 09:00');
    $session = WorkSession::start();

    $this->travelTo('2026-08-27 11:00');

    expect($session->hours())->toBe(0.0)
        ->and($session->elapsedHours())->toBe(2.0);
});

it('finds the session currently running', function () {
    WorkSession::factory()->on('2026-08-26')->create();
    $running = WorkSession::start();

    expect(WorkSession::current()->id)->toBe($running->id);
});

it('has no current session when none is running', function () {
    WorkSession::factory()->on('2026-08-26')->create();

    expect(WorkSession::current())->toBeNull();
});

it('attributes a session to the date it started', function () {
    $session = WorkSession::factory()->create([
        'started_at' => '2026-08-10 22:00',
        'ended_at' => '2026-08-11 02:00',
    ]);

    expect($session->date()->toDateString())->toBe('2026-08-10')
        ->and($session->hours())->toBe(4.0);
});

it('scopes sessions to the date they started on', function () {
    WorkSession::factory()->on('2026-08-10')->create();
    WorkSession::factory()->on('2026-08-11')->create();

    expect(WorkSession::query()->startedOn(now()->parse('2026-08-10'))->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Notes
|--------------------------------------------------------------------------
*/

it('appends each note on its own line', function () {
    $session = WorkSession::factory()->on('2026-08-27')->create();

    $session->appendNote('First thing')->appendNote('Second thing');

    expect($session->fresh()->note)->toBe("First thing\nSecond thing");
});

it('trims a note and ignores an empty one', function () {
    $session = WorkSession::factory()->on('2026-08-27')->create();

    $session->appendNote('  Padded  ')->appendNote('   ');

    expect($session->fresh()->note)->toBe('Padded');
});

it('refuses a note that would pass the limit', function () {
    $session = WorkSession::factory()->on('2026-08-27')->create([
        'note' => str_repeat('a', WorkSession::NoteLimit),
    ]);

    expect(fn () => $session->appendNote('one more'))
        ->toThrow(RuntimeException::class, 'This note has no room left.');

    expect($session->fresh()->note)->toHaveLength(WorkSession::NoteLimit);
});
