<?php

namespace App\Http\Controllers\Api;

use App\Enums\WorkKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkSessionResource;
use App\Models\WorkArea;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The timer as a menu bar app sees it: what is running, and starting or
 * stopping it. Mirrors the dashboard so the two never disagree.
 */
class TimerController extends Controller
{
    /**
     * Show the running session, or null when the clock is stopped.
     */
    public function show(): JsonResponse
    {
        return $this->respondWith(WorkSession::current());
    }

    /**
     * Start the timer.
     *
     * The dashboard remembers the last kind and area and quietly forgets an
     * area that has since been archived. A client is expected to be explicit
     * instead, so an archived or unknown area is refused rather than dropped.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['sometimes', Rule::enum(WorkKind::class)],
            'work_area_id' => [
                'nullable',
                'integer',
                Rule::exists(WorkArea::class, 'id')->whereNull('archived_at'),
            ],
        ], [
            'work_area_id.exists' => 'That area is not one you can book to.',
        ]);

        if (WorkSession::current() !== null) {
            return response()->json(['message' => 'A work session is already running.'], 409);
        }

        $session = WorkSession::start(
            kind: WorkKind::tryFrom($validated['kind'] ?? '') ?? WorkKind::Contracted,
            workAreaId: $validated['work_area_id'] ?? null,
        );

        return $this->respondWith($session, 201);
    }

    /**
     * Stop the running timer, now or at an earlier moment.
     *
     * An idle detector that notices you left twenty minutes ago passes the
     * moment it last saw you as `ended_at`, so the idle time is not recorded
     * as work. A stop with no time is a stop now, and as on the dashboard a
     * start and stop within the same second is a mis-click rather than work,
     * so the session is discarded instead of being saved as zero hours.
     */
    public function stop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ended_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ], [
            'ended_at.before_or_equal' => 'A work session cannot end in the future.',
        ]);

        $session = WorkSession::current();

        if ($session === null) {
            return response()->json(['message' => 'No work session is running.'], 409);
        }

        if (! isset($validated['ended_at'])) {
            if (now()->lessThanOrEqualTo($session->started_at)) {
                $session->delete();

                return $this->respondWith(null);
            }

            return $this->respondWith($session->stop());
        }

        $endedAt = Carbon::parse($validated['ended_at']);

        if ($endedAt->lessThanOrEqualTo($session->started_at)) {
            throw ValidationException::withMessages([
                'ended_at' => 'A work session must end after it starts.',
            ]);
        }

        return $this->respondWith($session->stop($endedAt));
    }

    /**
     * Correct when the running session started.
     *
     * For the morning the timer was pressed late: the work began at five past
     * nine, not when it was remembered. The session stays running.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'started_at' => ['required', 'date', 'before:now'],
        ], [
            'started_at.before' => 'A work session cannot start in the future.',
        ]);

        $session = WorkSession::current();

        if ($session === null) {
            return response()->json(['message' => 'No work session is running.'], 409);
        }

        $session->update(['started_at' => Carbon::parse($validated['started_at'])]);

        return $this->respondWith($session);
    }

    /**
     * Discard the running session without recording it.
     *
     * For a timer started by mistake, or left running over a break that was
     * not work at all.
     */
    public function destroy(): JsonResponse
    {
        $session = WorkSession::current();

        if ($session === null) {
            return response()->json(['message' => 'No work session is running.'], 409);
        }

        $session->delete();

        return response()->json(null, 204);
    }

    /**
     * Wrap a session for the response, with its area alongside.
     */
    protected function respondWith(?WorkSession $session, int $status = 200): JsonResponse
    {
        if ($session === null) {
            return response()->json(['data' => null], $status);
        }

        return WorkSessionResource::make($session->load('workArea'))
            ->response()
            ->setStatusCode($status);
    }
}
