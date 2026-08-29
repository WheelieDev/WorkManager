<?php

namespace App\Support;

use App\Enums\LeaveType;
use App\Enums\WorkKind;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Works out the time off in lieu banked, taken, and still owed back.
 *
 * Unlike the other pots, this one is not awarded for a leave year: it is earned
 * an hour at a time by tracking work as lieu, and spent by booking it as leave.
 * That makes it a running balance with no turnover date, which is why it counts
 * everything ever recorded rather than a year's worth. It cannot go below zero,
 * because you cannot take back time you have not yet worked.
 */
class LieuLedger
{
    protected FlexiCalculator $calculator;

    public function __construct()
    {
        $this->calculator = new FlexiCalculator;
    }

    /**
     * Get the balance the tracking started from.
     *
     * Whatever was owed before any of this was recorded, entered by hand.
     */
    public function openingBalance(): float
    {
        return round((float) Setting::read('lieu_opening_hours', config('leave.lieu_opening_hours', 0)), 2);
    }

    /**
     * Determine whether an opening balance has been entered.
     */
    public function openingWasEntered(): bool
    {
        return Setting::query()->where('key', 'lieu_opening_hours')->exists();
    }

    /**
     * Get the hours worked in lieu, across everything recorded.
     *
     * Only completed sessions count: a running timer banks nothing until it is
     * stopped, which is the same reading the flexi balance gets.
     */
    public function earned(): float
    {
        return round(
            $this->sessions()->sum(fn (WorkSession $session): float => $session->hours()),
            2,
        );
    }

    /**
     * Get the sessions that banked the lieu, newest first.
     *
     * @return Collection<int, WorkSession>
     */
    public function sessions(): Collection
    {
        return WorkSession::query()
            ->completed()
            ->ofKind(WorkKind::Lieu)
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * Get the lieu days booked off, across everything recorded.
     *
     * @return Collection<int, LeaveDay>
     */
    public function bookedDays(): Collection
    {
        return LeaveDay::query()
            ->where('type', LeaveType::Lieu)
            ->orderBy('date')
            ->get();
    }

    /**
     * Get the hours of lieu taken back as leave.
     */
    public function booked(): float
    {
        return round(
            $this->bookedDays()->sum(fn (LeaveDay $day): float => $this->effectiveHours($day)),
            2,
        );
    }

    /**
     * Get the requests that spent lieu, newest first.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function requests(): Collection
    {
        return LeaveRequest::query()
            ->with('days')
            ->whereHas('days', fn (Builder $days) => $days->where('type', LeaveType::Lieu))
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get the hours a day of lieu leave is actually worth.
     *
     * A day the pattern does not work costs nothing, and no day can cost more
     * than it works, which is the reading every other pot gets.
     */
    public function effectiveHours(LeaveDay $day): float
    {
        if ($this->calculator->isBankHoliday($day->date)) {
            return 0.0;
        }

        return round(min($day->hours, $this->calculator->patternHours($day->date)), 2);
    }

    /**
     * Get the lieu still owed back to you.
     */
    public function balance(): float
    {
        return round($this->openingBalance() + $this->earned() - $this->booked(), 2);
    }

    /**
     * Get what the balance would be if the given hours were booked off.
     *
     * @param  float  $creditedBack  Hours already booked that are being replaced.
     */
    public function balanceAfter(float $hours, float $creditedBack = 0.0): float
    {
        return round($this->balance() + $creditedBack - $hours, 2);
    }

    /**
     * Determine whether there is enough banked to book the given hours.
     */
    public function canAfford(float $hours, float $creditedBack = 0.0): bool
    {
        return $this->balanceAfter($hours, $creditedBack) >= 0;
    }

    /**
     * Determine whether anything has been banked or taken at all.
     */
    public function isUsed(): bool
    {
        return $this->openingBalance() != 0.0 || $this->earned() > 0 || $this->booked() > 0;
    }

    /**
     * Express hours as days at the average working day of the current pattern.
     */
    public function asDays(float $hours): ?float
    {
        $dayLength = WorkPattern::inEffectOn(now())?->days
            ->filter(fn ($day): bool => $day->isWorked())
            ->avg(fn ($day): float => $day->hours());

        return $dayLength > 0 ? round($hours / $dayLength, 1) : null;
    }
}
