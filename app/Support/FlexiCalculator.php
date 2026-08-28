<?php

namespace App\Support;

use App\Enums\Weekday;
use App\Models\BankHoliday;
use App\Models\WorkPattern;
use App\Models\WorkSession;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Works out expected hours, worked hours, and the resulting flexi balance.
 */
class FlexiCalculator
{
    /**
     * Cached patterns with their days, keyed by pattern id.
     *
     * @var array<int, WorkPattern>|null
     */
    protected ?array $patterns = null;

    /**
     * Cached worked hours, keyed by date.
     *
     * @var array<string, float>
     */
    protected array $worked = [];

    /**
     * Cached bank holiday titles, keyed by date.
     *
     * @var array<string, string>|null
     */
    protected ?array $bankHolidays = null;

    /**
     * Get the hours the pattern expects to be worked on the given date.
     */
    public function expectedHours(DateTimeInterface $date): float
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($this->isBankHoliday($date)) {
            return 0.0;
        }

        $weekday = Weekday::tryFrom($date->dayOfWeekIso);

        if ($weekday === null) {
            return 0.0;
        }

        $pattern = $this->patternFor($date);

        if ($pattern === null || ! $pattern->worksMonth((int) $date->month)) {
            return 0.0;
        }

        return $pattern->days->firstWhere('weekday', $weekday)?->hours() ?? 0.0;
    }

    /**
     * Get the hours actually worked on the given date.
     *
     * Only completed sessions count. A running timer moves the balance when it
     * is stopped, not before.
     */
    public function workedHours(DateTimeInterface $date): float
    {
        $key = Carbon::parse($date)->toDateString();

        return $this->worked[$key] ??= round(
            WorkSession::query()
                ->completed()
                ->startedOn(Carbon::parse($date))
                ->get()
                ->sum(fn (WorkSession $session): float => $session->hours()),
            2,
        );
    }

    /**
     * Load worked hours for a date range in one query.
     *
     * Without this, a period balance costs a query per day, and the period
     * view would multiply that by six weeks.
     */
    public function warmRange(DateTimeInterface $from, DateTimeInterface $to): void
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        $byDate = WorkSession::query()
            ->completed()
            ->whereBetween('started_at', [$from, $to])
            ->get()
            ->groupBy(fn (WorkSession $session): string => $session->date()->toDateString());

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->toDateString();

            $this->worked[$key] ??= round(
                $byDate->get($key, collect())->sum(fn (WorkSession $session): float => $session->hours()),
                2,
            );
        }
    }

    /**
     * Get the difference between hours worked and hours expected on a date.
     */
    public function deltaFor(DateTimeInterface $date): float
    {
        if (! $this->isTracked($date)) {
            return 0.0;
        }

        return round($this->workedHours($date) - $this->expectedHours($date), 2);
    }

    /**
     * Determine whether the balance counts the given date.
     */
    public function isTracked(DateTimeInterface $date): bool
    {
        return FlexiPeriod::isTracked($date);
    }

    /**
     * Get the balance accrued within a period, ignoring anything carried in.
     *
     * Only days that have actually happened are counted.
     */
    public function accruedIn(FlexiPeriod $period): float
    {
        $through = $period->countedThrough();
        $from = $period->countedFrom();

        if ($through === null || $from === null || $from->greaterThan($through)) {
            return 0.0;
        }

        $this->warmRange($from, $through);

        return round(
            $period->dates()
                ->filter(fn (Carbon $date): bool => $date->betweenIncluded($from, $through))
                ->sum(fn (Carbon $date): float => $this->deltaFor($date)),
            2,
        );
    }

    /**
     * Get the balance carried into a period from the ones before it.
     *
     * Each period's closing balance is capped at the positive limit before it
     * carries; negative balances carry in full, so underworked hours are never
     * written off.
     */
    public function carryInto(FlexiPeriod $period): float
    {
        $carry = 0.0;

        for ($index = 0; $index < $period->index; $index++) {
            $earlier = new FlexiPeriod($index);

            if (! $earlier->hasClosed()) {
                break;
            }

            $carry = min($carry + $this->accruedIn($earlier), FlexiPeriod::limit());
        }

        return round($carry, 2);
    }

    /**
     * Get the balance for a period, including anything carried in.
     */
    public function balanceFor(FlexiPeriod $period): float
    {
        return round($this->carryInto($period) + $this->accruedIn($period), 2);
    }

    /**
     * Determine whether a balance is outside the allowed range.
     */
    public function isOverLimit(float $balance): bool
    {
        return abs($balance) > FlexiPeriod::limit();
    }

    /**
     * Determine whether the pattern works the given date's weekday.
     *
     * This ignores bank holidays: a holiday falling on a working Monday is
     * still a day the pattern works, it just expects no hours.
     */
    public function isPatternWorkingDay(DateTimeInterface $date): bool
    {
        $date = Carbon::parse($date)->startOfDay();

        if (! $this->isTracked($date)) {
            return false;
        }

        $weekday = Weekday::tryFrom($date->dayOfWeekIso);

        if ($weekday === null) {
            return false;
        }

        $pattern = $this->patternFor($date);

        if ($pattern === null) {
            // With no pattern to go on, treat the working week as normal so
            // there is somewhere to record time before one is set up.
            return true;
        }

        if (! $pattern->worksMonth((int) $date->month)) {
            return false;
        }

        return (bool) $pattern->days->firstWhere('weekday', $weekday)?->isWorked();
    }

    /**
     * Determine whether the given date is a bank holiday.
     */
    public function isBankHoliday(DateTimeInterface $date): bool
    {
        return $this->bankHolidayTitle($date) !== null;
    }

    /**
     * Get the name of the bank holiday falling on the given date, if any.
     */
    public function bankHolidayTitle(DateTimeInterface $date): ?string
    {
        $this->bankHolidays ??= BankHoliday::titlesByDate();

        return $this->bankHolidays[Carbon::parse($date)->toDateString()] ?? null;
    }

    /**
     * Get the pattern in effect on the given date.
     */
    protected function patternFor(Carbon $date): ?WorkPattern
    {
        $this->patterns ??= WorkPattern::query()
            ->with('days')
            ->orderByDesc('start_date')
            ->get()
            ->all();

        foreach ($this->patterns as $pattern) {
            if ($pattern->start_date->lessThanOrEqualTo($date)) {
                return $pattern;
            }
        }

        return null;
    }
}
