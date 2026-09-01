<?php

namespace App\Support;

use App\Enums\LeaveType;
use App\Enums\Weekday;
use App\Enums\WorkKind;
use App\Models\BankHoliday;
use App\Models\LeaveDay;
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
     * Cached hours worked in lieu, keyed by date.
     *
     * @var array<string, float>
     */
    protected array $lieu = [];

    /**
     * Cached bank holiday titles, keyed by date.
     *
     * @var array<string, string>|null
     */
    protected ?array $bankHolidays = null;

    /**
     * Cached leave hours, keyed by date.
     *
     * @var array<string, float>|null
     */
    protected ?array $leave = null;

    /**
     * Cached phased return hours, keyed by date.
     *
     * @var array<string, float>|null
     */
    protected ?array $phased = null;

    /**
     * Get the hours the pattern expects to be worked on the given date.
     */
    public function expectedHours(DateTimeInterface $date): float
    {
        return round(max(0, $this->patternHours($date) - $this->coveredHours($date)), 2);
    }

    /**
     * Get the hours the pattern asks for on a date, before anything covers it.
     *
     * This is the figure a bank holiday or a day of leave is worth, which is
     * why it ignores both.
     */
    public function patternHours(DateTimeInterface $date): float
    {
        $date = Carbon::parse($date)->startOfDay();

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
     * Get the hours on a date already accounted for without working them.
     *
     * A bank holiday covers the whole day. Leave covers the hours booked, so
     * a morning off leaves the afternoon still expected.
     */
    public function coveredHours(DateTimeInterface $date): float
    {
        $pattern = $this->patternHours($date);

        if ($pattern <= 0.0) {
            return 0.0;
        }

        if ($this->isBankHoliday($date)) {
            return $pattern;
        }

        return round(min($pattern, $this->leaveHours($date)), 2);
    }

    /**
     * Get the hours of leave booked on a date.
     */
    public function leaveHours(DateTimeInterface $date): float
    {
        $this->leave ??= LeaveDay::query()
            ->get()
            ->groupBy(fn (LeaveDay $leave): string => $leave->date->toDateString())
            ->map(fn ($onDay): float => round($onDay->sum('hours'), 2))
            ->all();

        return $this->leave[Carbon::parse($date)->toDateString()] ?? 0.0;
    }

    /**
     * Get the hours a phased return offsets on a date.
     *
     * These are part of the leave hours above, pulled out so a day can name
     * the phased return separately from leave booked alongside it.
     */
    public function phasedReturnHours(DateTimeInterface $date): float
    {
        $this->phased ??= LeaveDay::query()
            ->where('type', LeaveType::PhasedReturn)
            ->get()
            ->groupBy(fn (LeaveDay $leave): string => $leave->date->toDateString())
            ->map(fn ($onDay): float => round($onDay->sum('hours'), 2))
            ->all();

        return $this->phased[Carbon::parse($date)->toDateString()] ?? 0.0;
    }

    /**
     * Get the hours actually worked on the given date.
     *
     * Only completed sessions count, and only contracted ones. A running timer
     * moves the balance when it is stopped, not before, and time worked in lieu
     * is banked as leave rather than settled here.
     */
    public function workedHours(DateTimeInterface $date): float
    {
        $key = Carbon::parse($date)->toDateString();

        return $this->worked[$key] ??= round(
            WorkSession::query()
                ->completed()
                ->countingTowardsFlexi()
                ->startedOn(Carbon::parse($date))
                ->get()
                ->sum(fn (WorkSession $session): float => $session->hours()),
            2,
        );
    }

    /**
     * Load worked and lieu hours for a date range in one query.
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
            $onDay = $byDate->get($key, collect());

            $this->worked[$key] ??= round(
                $onDay->filter(fn (WorkSession $session): bool => $session->kind->countsTowardsFlexi())
                    ->sum(fn (WorkSession $session): float => $session->hours()),
                2,
            );

            $this->lieu[$key] ??= round(
                $onDay->where('kind', WorkKind::Lieu)->sum(fn (WorkSession $session): float => $session->hours()),
                2,
            );
        }
    }

    /**
     * Get the hours worked in lieu on the given date.
     *
     * These are shown beside the day's figures rather than in them: they buy
     * time off later instead of answering to the pattern now.
     */
    public function lieuHours(DateTimeInterface $date): float
    {
        $key = Carbon::parse($date)->toDateString();

        return $this->lieu[$key] ??= round(
            WorkSession::query()
                ->completed()
                ->ofKind(WorkKind::Lieu)
                ->startedOn(Carbon::parse($date))
                ->get()
                ->sum(fn (WorkSession $session): float => $session->hours()),
            2,
        );
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
