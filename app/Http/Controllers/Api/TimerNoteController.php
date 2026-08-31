<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkSessionResource;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TimerNoteController extends Controller
{
    /**
     * Add what you have just done to the running session's note.
     *
     * Kept to the running session, as on the dashboard: a note written now is
     * about the work happening now.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:'.WorkSession::NoteLimit],
        ], [
            'note.required' => 'Write something to add.',
        ]);

        $session = WorkSession::current();

        if ($session === null) {
            return response()->json(['message' => 'No work session is running.'], 409);
        }

        $remaining = WorkSession::NoteLimit - mb_strlen($session->note ?? '');

        if (mb_strlen(trim($validated['note'])) > $remaining) {
            throw ValidationException::withMessages([
                'note' => 'This note has no room left. Trim it on the timesheet first.',
            ]);
        }

        $session->appendNote($validated['note']);

        return WorkSessionResource::make($session->load('workArea'))->response();
    }
}
