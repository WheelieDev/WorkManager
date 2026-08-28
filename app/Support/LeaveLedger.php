<?php

namespace App\Support;

use App\Models\BankHoliday;
use App\Models\LeaveDay;
use App\Models\WorkPattern;
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
     * Get the bookable leave awarded for the year.
     */
    public function annualEntitlement(): float
    {
        return round((float) config('leave.annual_leave_hours'), 2);
    }

    /**
     * Get the pro-rata public holiday allowance in hours.
     */
    public function publicHolidayEntitlement(): float
    {
        $days = (float) config('leave.public_holiday_days');
        $dayLength = $this->fullTimeWeeklyHours() / 5;

        return round($days * $dayLength * $this->fraction(), 2);
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
        return round(max(0, $this->publicHolidayEntitlement() - $this->publicHolidaysUsed()), 2);
    }

    /**
     * Get the bank holiday hours the allowance did not stretch to.
     *
     * This is what comes out of bookable leave instead.
     */
    public function publicHolidayShortfall(): float
    {
        return round(max(0, $this->publicHolidaysUsed() - $this->publicHolidayEntitlement()), 2);
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
     * Get the hours of leave booked in this year.
     */
    public function annualBooked(): float
    {
        return round($this->booked()->sum('hours'), 2);
    }

    /**
     * Get everything drawn from bookable leave, including any shortfall.
     */
    public function annualUsed(): float
    {
        return round($this->annualBooked() + $this->publicHolidayShortfall(), 2);
    }

    /**
     * Get the bookable leave still available.
     */
    public function annualRemaining(): float
    {
        return round($this->annualEntitlement() - $this->annualUsed(), 2);
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
