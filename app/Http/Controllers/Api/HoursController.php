<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FlexiCalculator;
use App\Support\FlexiPeriod;
use Illuminate\Http\JsonResponse;

/**
 * Today's hours against the pattern, and where the flexi balance stands.
 *
 * The same figures the dashboard shows, worked out the same way.
 */
class HoursController extends Controller
{
    /**
     * Show today's figures and the period balance.
     *
     * Worked hours count completed contracted sessions only, as on the
     * dashboard: a running timer moves the figures when it is stopped, and a
     * client showing a live total adds the running session's elapsed time
     * itself.
     */
    public function show(FlexiCalculator $calculator): JsonResponse
    {
        $today = now();
        $period = FlexiPeriod::current();
        $balance = $calculator->balanceFor($period);

        return response()->json([
            'data' => [
                'date' => $today->toDateString(),
                'today' => [
                    'worked' => $calculator->workedHours($today),
                    'expected' => $calculator->expectedHours($today),
                    'difference' => $calculator->deltaFor($today),
                    'lieu' => $calculator->lieuHours($today),
                    'leave' => $calculator->leaveHours($today),
                    'bank_holiday' => $calculator->bankHolidayTitle($today),
                ],
                'period' => [
                    'label' => $period->label(),
                    'start' => $period->start->toDateString(),
                    'end' => $period->end->toDateString(),
                    'days_left' => max(0, (int) $today->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay(), false)),
                    'balance' => $balance,
                    'limit' => FlexiPeriod::limit(),
                    'over_limit' => $calculator->isOverLimit($balance),
                ],
            ],
        ]);
    }
}
