<?php

namespace App\Support;

use App\Enums\Weekday;
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
     * Get the hours the pattern expects to be worked on the given date.
     */
    public function expectedHours(DateTimeInterface $date): float
    {
        $date = Carbon::parse($date)->startOfDay();

        $weekday = Weekday::tryFrom($date->dayOfWeekIso);

        if ($weekday === null) {
            return 0.0;
        }

        $pattern = $this->patternFor($date);

        if ($pattern === null) {
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
     * Get the difference between hours worked and hours expected on a date.
     */
    public function deltaFor(DateTimeInterface $date): float
    {
        return round($this->workedHours($date) - $this->expectedHours($date), 2);
    }

    /**
     * Get the balance accrued within a period, ignoring anything carried in.
     *
     * Only days that have actually happened are counted.
     */
    public function accruedIn(FlexiPeriod $period): float
    {
        $through = $period->countedThrough();

        if ($through === null) {
            return 0.0;
        }

        return round(
            $period->dates()
                ->filter(fn (Carbon $date): bool => $date->lessThanOrEqualTo($through))
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
