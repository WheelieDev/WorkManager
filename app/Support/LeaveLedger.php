<?php

namespace App\Support;

use App\Enums\LeaveType;
use App\Models\BankHoliday;
use App\Models\LeaveCarryover;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\WorkPattern;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Works out the two entitlements for a leave year and what has drawn on them.
 *
 * Public holidays are kept apart from bookable leave on purpose: a part time
 * worker whose days cluster on Mondays loses more to bank holidays than the
 * pro-rata share covers, and the difference comes out of their own leave. That
 * difference is the number worth seeing.
 */
class LeaveLedger
{
    /**
     * How far back to follow the chain of carried balances.
     */
    protected const MaxCarryYears = 10;

    protected FlexiCalculator $calculator;

    public function __construct(public readonly LeaveYear $year)
    {
        $this->calculator = new FlexiCalculator;
    }

    /**
     * Get the hours a full time week works.
     */
    public function fullTimeWeeklyHours(): float
    {
        return (float) config('leave.full_time_weekly_hours');
    }

    /**
     * Get the hours a full time year works.
     */
    public function fullTimeYearlyHours(): float
    {
        return round($this->fullTimeWeeklyHours() * (float) config('leave.weeks_per_year'), 2);
    }

    /**
     * Get the pattern the entitlements are pro-rata'd from.
     *
     * The pattern in effect when the leave year opens, or the first one to
     * start during it if work began part way through.
     */
    public function pattern(): ?WorkPattern
    {
        return WorkPattern::inEffectOn($this->year->start)
            ?? WorkPattern::query()
                ->whereBetween('start_date', [$this->year->start->toDateString(), $this->year->end->toDateString()])
                ->orderBy('start_date')
                ->first();
    }

    /**
     * Get the contracted hours a week for this leave year.
     */
    public function weeklyHours(): float
    {
        return round($this->pattern()?->weeklyHours() ?? 0.0, 2);
    }

    /**
     * Get the share of a full time week this pattern works.
     */
    public function fraction(): float
    {
        $fullTime = $this->fullTimeWeeklyHours();

        return $fullTime > 0 ? round($this->weeklyHours() / $fullTime, 4) : 0.0;
    }

    /**
     * Get the hours this pattern works in a year.
     */
    public function yearlyHours(): float
    {
        return round($this->weeklyHours() * (float) config('leave.weeks_per_year'), 2);
    }

    /**
     * Get the weeks of the year under contract.
     */
    public function contractedWeeks(): float
    {
        return (float) (config('leave.contracted_weeks') ?? config('leave.weeks_per_year'));
    }

    /**
     * Get the share of the year under contract.
     *
     * Four weeks off in August is four weeks of entitlement not earned.
     */
    public function yearFraction(): float
    {
        $weeks = (float) config('leave.weeks_per_year');

        return $weeks > 0 ? round(min(1, $this->contractedWeeks() / $weeks), 6) : 1.0;
    }

    /**
     * Turn full time days into the hours they are worth here.
     */
    protected function daysToHours(float $days, bool $byWeeks = true): float
    {
        $dayLength = $this->fullTimeWeeklyHours() / 5;
        $weeks = $byWeeks ? $this->yearFraction() : 1.0;

        return round($days * $dayLength * $this->fraction() * $weeks, 2);
    }

    /**
     * Get the awarded annual leave account, if one has been set.
     *
     * An awarded figure is what the employer settled on, so it already has the
     * public holiday adjustment inside it and must not have it applied again.
     */
    public function awardedHours(): ?float
    {
        $awarded = config('leave.annual_leave_hours');

        return $awarded === null ? null : round((float) $awarded, 2);
    }

    /**
     * Determine whether the entitlement comes from an awarded figure.
     */
    public function isAwarded(): bool
    {
        return $this->awardedHours() !== null;
    }

    /**
     * Get the annual leave account for the year, before anything carried in.
     */
    public function baseEntitlement(): float
    {
        return $this->awardedHours() ?? $this->daysToHours((float) config('leave.annual_leave_days'));
    }

    /**
     * Get the hours carried into this year.
     *
     * A figure entered by hand wins. Otherwise it is what was left at the end
     * of the year before, but only where that year has leave recorded against
     * it; a year nobody has used is not evidence of nothing carried over.
     */
    public function carriedIn(int $depth = 0): float
    {
        $override = LeaveCarryover::query()->where('leave_year', $this->year->year)->first();

        if ($override !== null) {
            return $this->cappedCarry((float) $override->hours);
        }

        if ($depth >= self::MaxCarryYears) {
            return 0.0;
        }

        $previous = new self($this->year->previous());

        if (! $previous->hasLeaveRecorded()) {
            return 0.0;
        }

        return $this->cappedCarry($previous->annualRemaining($depth + 1));
    }

    /**
     * Determine whether the carried figure was entered by hand.
     */
    public function carryWasEntered(): bool
    {
        return LeaveCarryover::query()->where('leave_year', $this->year->year)->exists();
    }

    /**
     * Determine whether the year before this one can be worked out from.
     */
    public function hasLeaveRecorded(): bool
    {
        return $this->booked()->isNotEmpty() || $this->carryWasEntered();
    }

    /**
     * Apply the carryover cap, if there is one.
     */
    protected function cappedCarry(float $hours): float
    {
        $cap = config('leave.carryover_cap_hours');

        return round($cap === null ? $hours : min($hours, (float) $cap), 2);
    }

    /**
     * Get the bookable leave for the year, once public holidays are settled.
     *
     * Unspent public holiday hours are added here rather than left in their
     * own pot, because that is where they end up in practice.
     */
    public function annualEntitlement(int $depth = 0): float
    {
        $adjustment = $this->isAwarded() ? 0.0 : $this->publicHolidayAdjustment();

        return round($this->carriedIn($depth) + $this->baseEntitlement() + $adjustment, 2);
    }

    /**
     * Get what public holidays do to the bookable balance.
     *
     * Positive when the working pattern misses bank holidays and the unused
     * allowance carries over; negative when they land on working days more
     * often than the allowance covers.
     */
    public function publicHolidayAdjustment(): float
    {
        return round($this->publicHolidayEntitlement() - $this->publicHolidaysUsed(), 2);
    }

    /**
     * Get the unspent public holiday hours added to bookable leave.
     */
    public function publicHolidaySurplus(): float
    {
        return round(max(0, $this->publicHolidayAdjustment()), 2);
    }

    /**
     * Get the pro-rata public holiday allowance in hours.
     */
    public function publicHolidayEntitlement(): float
    {
        return $this->daysToHours(
            (float) config('leave.public_holiday_days'),
            (bool) config('leave.prorate_public_holidays'),
        );
    }

    /**
     * Get every bank holiday in the year, with what it costs this pattern.
     *
     * @return Collection<int, array{holiday: BankHoliday, hours: float}>
     */
    public function publicHolidays(): Collection
    {
        return BankHoliday::query()
            ->whereBetween('date', [$this->year->start->toDateString(), $this->year->end->toDateString()])
            ->orderBy('date')
            ->get()
            ->map(fn (BankHoliday $holiday): array => [
                'holiday' => $holiday,
                'hours' => $this->calculator->patternHours($holiday->date),
            ]);
    }

    /**
     * Get the hours bank holidays have taken from the pattern this year.
     */
    public function publicHolidaysUsed(): float
    {
        return round($this->publicHolidays()->sum('hours'), 2);
    }

    /**
     * Get the public holiday hours still unspent.
     */
    public function publicHolidaysRemaining(): float
    {
        return $this->publicHolidaySurplus();
    }

    /**
     * Get the bank holiday hours the allowance did not stretch to.
     *
     * This is what comes off bookable leave instead.
     */
    public function publicHolidayShortfall(): float
    {
        return round(max(0, -$this->publicHolidayAdjustment()), 2);
    }

    /**
     * Get the leave booked in this year.
     *
     * @return Collection<int, LeaveDay>
     */
    public function booked(): Collection
    {
        return LeaveDay::query()
            ->betweenDates($this->year->start, $this->year->end)
            ->orderBy('date')
            ->get();
    }

    /**
     * Get the leave of one type booked in this year.
     *
     * @return Collection<int, LeaveDay>
     */
    public function bookedOf(LeaveType $type): Collection
    {
        return $this->booked()->filter(fn (LeaveDay $leave): bool => $leave->type === $type)->values();
    }

    /**
     * Get the hours of annual leave booked in this year.
     *
     * Only annual leave draws on the annual pot, so a hospital appointment
     * costs no holiday. Only hours that actually buy time off count: leave
     * sitting on a bank holiday buys nothing, because the day is already paid
     * for, and leave beyond what a day works cannot buy more than the day.
     */
    public function annualBooked(): float
    {
        return $this->usedFor(LeaveType::Annual);
    }

    /**
     * Get the hours of one type used in this year.
     */
    public function usedFor(LeaveType $type): float
    {
        return round($this->bookedOf($type)->sum(fn (LeaveDay $leave): float => $this->effectiveHours($leave)), 2);
    }

    /**
     * Get the allowance for a type in hours, or null where it has no limit.
     */
    public function entitlementFor(LeaveType $type): ?float
    {
        if ($type->drawsOnAnnualLeave()) {
            return $this->annualEntitlement();
        }

        $days = $type->allowanceDays();

        return $days === null ? null : $this->daysToHours($days, $type->proratesByWeeks());
    }

    /**
     * Get what is left of a type's allowance, or null where it has no limit.
     */
    public function remainingFor(LeaveType $type): ?float
    {
        $entitlement = $this->entitlementFor($type);

        return $entitlement === null ? null : round($entitlement - $this->usedFor($type), 2);
    }

    /**
     * Get every type that has an allowance or something booked against it.
     *
     * @return Collection<int, LeaveAllowance>
     */
    public function otherLeave(): Collection
    {
        $allowances = [];

        foreach (LeaveType::separate() as $type) {
            $allowance = new LeaveAllowance($type, $this->entitlementFor($type), $this->usedFor($type));

            if ($allowance->isWorthShowing()) {
                $allowances[] = $allowance;
            }
        }

        return collect($allowances);
    }

    /**
     * Get the hours a booking is actually worth.
     */
    public function effectiveHours(LeaveDay $leave): float
    {
        if ($this->calculator->isBankHoliday($leave->date)) {
            return 0.0;
        }

        return round(min($leave->hours, $this->calculator->patternHours($leave->date)), 2);
    }

    /**
     * Get the leave requests touching this year, newest first.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function requests(): Collection
    {
        return LeaveRequest::query()
            ->with('days')
            ->overlapping($this->year->start, $this->year->end)
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get the hours a request actually costs, counting only days in this year.
     */
    public function requestHours(LeaveRequest $request): float
    {
        return round(
            $request->days
                ->filter(fn (LeaveDay $day): bool => $this->year->contains($day->date))
                ->sum(fn (LeaveDay $day): float => $this->effectiveHours($day)),
            2,
        );
    }

    /**
     * Get the days a request books within this year.
     */
    public function requestDays(LeaveRequest $request): int
    {
        return $request->days
            ->filter(fn (LeaveDay $day): bool => $this->year->contains($day->date))
            ->count();
    }

    /**
     * Count the bank holidays falling inside a request's dates.
     *
     * These are days off the request did not have to pay for.
     */
    public function requestPublicHolidays(LeaveRequest $request): int
    {
        return BankHoliday::query()
            ->whereBetween('date', [
                $request->starts_on->toDateString(),
                $request->ends_on->toDateString(),
            ])
            ->count();
    }

    /**
     * Get the hours the pattern asks for on a date.
     */
    public function patternHours(DateTimeInterface $date): float
    {
        return $this->calculator->patternHours($date);
    }

    /**
     * Explain why a booking is not costing what it says, if it is not.
     */
    public function bookingNote(LeaveDay $leave): ?string
    {
        if ($this->calculator->isBankHoliday($leave->date)) {
            return 'already a bank holiday';
        }

        $pattern = $this->calculator->patternHours($leave->date);

        if ($pattern <= 0) {
            return 'not a working day';
        }

        return $leave->hours > $pattern ? 'more than the day works' : null;
    }

    /**
     * Get the leave drawn from the bookable balance.
     */
    public function annualUsed(): float
    {
        return $this->annualBooked();
    }

    /**
     * Get the bookable leave still available.
     */
    public function annualRemaining(int $depth = 0): float
    {
        return round($this->annualEntitlement($depth) - $this->annualUsed(), 2);
    }

    /**
     * Determine whether more leave has been used than was awarded.
     */
    public function isOverdrawn(): bool
    {
        return $this->annualRemaining() < 0;
    }

    /**
     * Express hours as days at this pattern's average working day.
     */
    public function asDays(float $hours): ?float
    {
        $dayLength = $this->pattern()?->days
            ->filter(fn ($day): bool => $day->isWorked())
            ->avg(fn ($day): float => $day->hours());

        return $dayLength > 0 ? round($hours / $dayLength, 1) : null;
    }
}
