<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signing another app in with the PIN, the way the login page does.
 *
 * Wrong guesses count against the same bucket as the login page, so the API
 * is not a second set of attempts at the same PIN.
 */
class TokenController extends Controller
{
    /**
     * Exchange the PIN for a token, named for the device that will hold it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'digits:6'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $this->ensureIsNotRateLimited($request);

        $user = User::query()->first();

        if ($user === null || ! Hash::check($validated['pin'], $user->pin)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'pin' => __('That PIN is incorrect.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        return response()->json([
            'token' => $user->createToken($validated['device_name'])->plainTextToken,
        ], 201);
    }

    /**
     * Revoke the token this request was made with, signing the device out.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    /**
     * Refuse the request once too many PINs have been tried from this address.
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'pin' => __('Too many attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    /**
     * Get the rate limiting key, shared with the login page.
     */
    protected function throttleKey(Request $request): string
    {
        return 'login:'.$request->ip();
    }
}
