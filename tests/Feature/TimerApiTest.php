<?php

use App\Enums\WorkKind;
use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkSession;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-08-27 10:00');
});

it('returns 401 without a token', function (string $method, string $url) {
    $this->json($method, $url)->assertUnauthorized();
})->with([
    ['GET', '/api/timer'],
    ['POST', '/api/timer/start'],
    ['POST', '/api/timer/stop'],
    ['POST', '/api/timer/notes'],
]);

it('signs in with a token made on the settings page', function () {
    $token = User::factory()->create()->createToken('Menu bar')->plainTextToken;

    $this->withToken($token)->getJson('/api/timer')->assertOk();
});

describe('signed in', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('reports nothing running when the clock is stopped', function () {
        $this->getJson('/api/timer')->assertOk()->assertExactJson(['data' => null]);
    });

    it('reports the running session', function () {
        $area = WorkArea::factory()->create(['name' => 'Licensing']);
        WorkSession::factory()->running()->forArea($area)->withNote('Renewals')->create([
            'started_at' => '2026-08-27 09:00',
        ]);

        $this->getJson('/api/timer')
            ->assertOk()
            ->assertJsonPath('data.started_at', '2026-08-27T09:00:00+01:00')
            ->assertJsonPath('data.ended_at', null)
            ->assertJsonPath('data.kind.value', 'contracted')
            ->assertJsonPath('data.kind.label', 'Contracted hours')
            ->assertJsonPath('data.work_area.name', 'Licensing')
            ->assertJsonPath('data.note', 'Renewals');
    });

    it('starts the timer', function () {
        $this->postJson('/api/timer/start')
            ->assertCreated()
            ->assertJsonPath('data.started_at', '2026-08-27T10:00:00+01:00')
            ->assertJsonPath('data.kind.value', 'contracted')
            ->assertJsonPath('data.work_area', null);

        expect(WorkSession::current())->not->toBeNull();
    });

    it('starts the timer as the chosen kind against the chosen area', function () {
        $area = WorkArea::factory()->create(['name' => 'Licensing']);

        $this->postJson('/api/timer/start', ['kind' => 'lieu', 'work_area_id' => $area->id])
            ->assertCreated()
            ->assertJsonPath('data.kind.value', 'lieu')
            ->assertJsonPath('data.work_area.id', $area->id);

        expect(WorkSession::current())
            ->kind->toBe(WorkKind::Lieu)
            ->work_area_id->toBe($area->id);
    });

    it('returns 422 for an archived area', function () {
        $area = WorkArea::factory()->archived()->create();

        $this->postJson('/api/timer/start', ['work_area_id' => $area->id])
            ->assertJsonValidationErrors(['work_area_id' => 'That area is not one you can book to.']);

        expect(WorkSession::current())->toBeNull();
    })->note('The dashboard quietly forgets a remembered area; a client is expected to be explicit.');

    it('returns 422 for an unknown kind', function () {
        $this->postJson('/api/timer/start', ['kind' => 'overtime'])
            ->assertJsonValidationErrors('kind');
    });

    it('returns 409 when starting while a session is running', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->postJson('/api/timer/start')
            ->assertConflict()
            ->assertJsonPath('message', 'A work session is already running.');

        expect(WorkSession::query()->count())->toBe(1);
    });

    it('stops the running timer', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->postJson('/api/timer/stop')
            ->assertOk()
            ->assertJsonPath('data.ended_at', '2026-08-27T10:00:00+01:00');

        expect(WorkSession::current())->toBeNull()
            ->and(WorkSession::query()->sole()->hours())->toBe(1.0);
    });

    it('discards a session stopped within the second it started', function () {
        WorkSession::factory()->running()->create(['started_at' => now()]);

        $this->postJson('/api/timer/stop')->assertOk()->assertExactJson(['data' => null]);

        expect(WorkSession::query()->count())->toBe(0);
    })->note('A start and stop in the same second is a mis-click, as on the dashboard.');

    it('returns 409 when stopping with nothing running', function () {
        $this->postJson('/api/timer/stop')
            ->assertConflict()
            ->assertJsonPath('message', 'No work session is running.');
    });

    it('appends a note to the running session', function () {
        WorkSession::factory()->running()->withNote('Cleared the backlog')->create([
            'started_at' => '2026-08-27 09:00',
        ]);

        $this->postJson('/api/timer/notes', ['note' => 'Answered the phones'])
            ->assertOk()
            ->assertJsonPath('data.note', "Cleared the backlog\nAnswered the phones");

        expect(WorkSession::current()->note)->toBe("Cleared the backlog\nAnswered the phones");
    });

    it('returns 422 for an empty note', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->postJson('/api/timer/notes', ['note' => ''])
            ->assertJsonValidationErrors(['note' => 'Write something to add.']);
    });

    it('returns 422 when the note has no room left', function () {
        WorkSession::factory()->running()->withNote(str_repeat('a', WorkSession::NoteLimit - 2))->create([
            'started_at' => '2026-08-27 09:00',
        ]);

        $this->postJson('/api/timer/notes', ['note' => 'abc'])
            ->assertJsonValidationErrors(['note' => 'This note has no room left. Trim it on the timesheet first.']);

        expect(mb_strlen(WorkSession::current()->note))->toBe(WorkSession::NoteLimit - 2);
    });

    it('returns 409 when adding a note with nothing running', function () {
        $this->postJson('/api/timer/notes', ['note' => 'Answered the phones'])
            ->assertConflict()
            ->assertJsonPath('message', 'No work session is running.');
    });
});

describe('trimming and correcting', function () {
    beforeEach(function () {
        Sanctum::actingAs(User::factory()->create());
    });

    it('stops the timer at an earlier moment', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->postJson('/api/timer/stop', ['ended_at' => '2026-08-27T09:40:00+01:00'])
            ->assertOk()
            ->assertJsonPath('data.ended_at', '2026-08-27T09:40:00+01:00');

        expect(WorkSession::query()->sole()->hours())->toBe(0.67);
    })->note('An idle detector passes the moment it last saw you, so idle time is not recorded as work.');

    it('returns 422 when stopping before the session started', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->postJson('/api/timer/stop', ['ended_at' => '2026-08-27T08:59:00+01:00'])
            ->assertJsonValidationErrors(['ended_at' => 'A work session must end after it starts.']);

        expect(WorkSession::current())->not->toBeNull();
    });

    it('returns 422 when stopping in the future', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->postJson('/api/timer/stop', ['ended_at' => '2026-08-27T10:01:00+01:00'])
            ->assertJsonValidationErrors(['ended_at' => 'A work session cannot end in the future.']);
    });

    it('corrects when the running session started', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:30']);

        $this->patchJson('/api/timer', ['started_at' => '2026-08-27T09:05:00+01:00'])
            ->assertOk()
            ->assertJsonPath('data.started_at', '2026-08-27T09:05:00+01:00')
            ->assertJsonPath('data.ended_at', null);

        expect(WorkSession::current()->started_at->format('H:i'))->toBe('09:05');
    });

    it('returns 422 when the corrected start is not in the past', function () {
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:30']);

        $this->patchJson('/api/timer', ['started_at' => '2026-08-27T10:00:00+01:00'])
            ->assertJsonValidationErrors(['started_at' => 'A work session cannot start in the future.']);

        $this->patchJson('/api/timer')->assertJsonValidationErrors('started_at');
    });

    it('returns 409 when correcting with nothing running', function () {
        $this->patchJson('/api/timer', ['started_at' => '2026-08-27T09:05:00+01:00'])
            ->assertConflict()
            ->assertJsonPath('message', 'No work session is running.');
    });

    it('discards the running session', function () {
        WorkSession::factory()->on('2026-08-26')->create();
        WorkSession::factory()->running()->create(['started_at' => '2026-08-27 09:00']);

        $this->deleteJson('/api/timer')->assertNoContent();

        expect(WorkSession::current())->toBeNull()
            ->and(WorkSession::query()->count())->toBe(1);
    });

    it('returns 409 when discarding with nothing running', function () {
        $this->deleteJson('/api/timer')
            ->assertConflict()
            ->assertJsonPath('message', 'No work session is running.');
    });
});
