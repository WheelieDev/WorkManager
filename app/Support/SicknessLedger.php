<?php

namespace App\Support;

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Models\Setting;
use App\Models\WorkPattern;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Works out occupational sick pay for a day of sickness.
 *
 * Two things make this unlike the rest of the leave in this application. The
 * entitlement is banded by length of service rather than fixed for the year,
 * and it is reduced by the sickness already taken in the twelve months before
 * the absence begins, which is a window that rolls with each absence and has
 * nothing to do with the leave year.
 */
class SicknessLedger
{
    protected FlexiCalculator $calculator;

    protected bool $serviceResolved = false;

    protected ?Carbon $serviceStarted = null;

    public function __construct()
    {
        $this->calculator = new FlexiCalculator;
    }

    /**
     * Get the day continuous service began, if it has been set.
     *
     * A date entered on the settings page wins over the config default.
     */
    public function serviceStarted(): ?Carbon
    {
        // Held for the life of this ledger: one page asks for it many times,
        // and holding it any longer than that would outlive a change to it.
        if ($this->serviceResolved) {
            return $this->serviceStarted;
        }

        $started = Setting::read('service_started', config('leave.service_started'));

        $this->serviceResolved = true;
        $this->serviceStarted = $started === null || $started === ''
            ? null
            : Carbon::parse((string) $started)->startOfDay();

        return $this->serviceStarted;
    }

    /**
     * Determine whether enough is known to work out an entitlement.
     */
    public function isConfigured(): bool
    {
        return $this->serviceStarted() !== null;
    }

    /**
     * Get the completed months of service as at a date.
     */
    public function monthsOfService(?DateTimeInterface $date = null): ?int
    {
        $started = $this->serviceStarted();

        if ($started === null) {
            return null;
        }

        $on = Carbon::parse($date ?? now())->startOfDay();

        return $on->lessThan($started) ? 0 : (int) $started->diffInMonths($on);
    }

    /**
     * Get the completed years of service as at a date.
     */
    public function yearsOfService(?DateTimeInterface $date = null): ?int
    {
        $months = $this->monthsOfService($date);

        return $months === null ? null : intdiv($months, 12);
    }

    /**
     * Get the year of service a date falls in.
     *
     * The policy bands absence by the year being served rather than by the
     * years completed, so somebody three years in is in their fourth year.
     */
    public function yearOfService(?DateTimeInterface $date = null): ?int
    {
        $years = $this->yearsOfService($date);

        return $years === null ? null : $years + 1;
    }

    /**
     * Say which year of service a date falls in, as the policy words it.
     */
    public function yearOfServiceLabel(?DateTimeInterface $date = null): ?string
    {
        $year = $this->yearOfService($date);

        return $year === null ? null : Number::ordinal($year).' year of service';
    }

    /**
     * Get the band that applies on the first day of an absence.
     *
     * The longest band the service has reached wins, so a policy expressed as
     * "after five years" needs no special case.
     */
    public function band(?DateTimeInterface $date = null): ?SicknessBand
    {
        $months = $this->monthsOfService($date);

        if ($months === null) {
            return null;
        }

        $bands = collect((array) config('leave.sickness.bands'))
            ->map(fn (array $band): SicknessBand => SicknessBand::fromConfig($band))
            ->sortByDesc(fn (SicknessBand $band): int => $band->afterMonths);

        return $bands->first(fn (SicknessBand $band): bool => $months >= $band->afterMonths);
    }

    /**
     * Get the hours a month of pay is worth at the pattern in effect.
     */
    public function monthHours(?DateTimeInterface $date = null): float
    {
        $on = Carbon::parse($date ?? now())->startOfDay();
        $weekly = WorkPattern::inEffectOn($on)?->weeklyHours() ?? 0.0;
        $weeksPerMonth = (float) config('leave.weeks_per_year') / 12;

        return round($weekly * $weeksPerMonth, 2);
    }

    /**
     * Get the full pay entitlement in hours, before anything already taken.
     */
    public function fullPayEntitlement(?DateTimeInterface $date = null): float
    {
        $band = $this->band($date);

        return $band === null ? 0.0 : round($band->fullPayMonths * $this->monthHours($date), 2);
    }

    /**
     * Get the half pay entitlement in hours, before anything already taken.
     */
    public function halfPayEntitlement(?DateTimeInterface $date = null): float
    {
        $band = $this->band($date);

        return $band === null ? 0.0 : round($band->halfPayMonths * $this->monthHours($date), 2);
    }

    /**
     * Get the first day of the rolling window that reduces the entitlement.
     */
    public function windowStart(?DateTimeInterface $date = null): Carbon
    {
        $months = (int) (config('leave.sickness.lookback_months') ?? 12);

        return Carbon::parse($date ?? now())->startOfDay()->subMonths($months);
    }

    /**
     * Get the sickness taken in the twelve months before an absence begins.
     *
     * The day itself is excluded: the policy counts what came before it.
     *
     * @return Collection<int, LeaveDay>
     */
    public function absencesBefore(?DateTimeInterface $date = null): Collection
    {
        $on = Carbon::parse($date ?? now())->startOfDay();

        return LeaveDay::query()
            ->where('type', LeaveType::Sickness)
            ->where('date', '>=', $this->windowStart($on)->toDateString())
            ->where('date', '<', $on->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Get the hours of sickness counted against the entitlement.
     */
    public function used(?DateTimeInterface $date = null): float
    {
        return round(
            $this->absencesBefore($date)->sum(fn (LeaveDay $day): float => $this->effectiveHours($day)),
            2,
        );
    }

    /**
     * Get the hours a day of sickness is actually worth.
     *
     * A day the pattern does not work costs nothing, and no day can cost more
     * than it works, which is the same reading annual leave gets.
     */
    public function effectiveHours(LeaveDay $day): float
    {
        if ($this->calculator->isBankHoliday($day->date)) {
            return 0.0;
        }

        return round(min($day->hours, $this->calculator->patternHours($day->date)), 2);
    }

    /**
     * Get the full pay still available.
     *
     * Sickness draws on full pay first, so what has been used comes off here
     * before it touches half pay.
     */
    public function fullPayRemaining(?DateTimeInterface $date = null): float
    {
        return round(max(0, $this->fullPayEntitlement($date) - $this->used($date)), 2);
    }

    /**
     * Get the half pay still available, once full pay has been exhausted.
     */
    public function halfPayRemaining(?DateTimeInterface $date = null): float
    {
        $beyondFullPay = max(0, $this->used($date) - $this->fullPayEntitlement($date));

        return round(max(0, $this->halfPayEntitlement($date) - $beyondFullPay), 2);
    }

    /**
     * Determine whether the entitlement has run out entirely.
     */
    public function isExhausted(?DateTimeInterface $date = null): bool
    {
        return $this->fullPayRemaining($date) <= 0 && $this->halfPayRemaining($date) <= 0;
    }

    /**
     * Express hours as months of pay at the pattern in effect.
     */
    public function asMonths(float $hours, ?DateTimeInterface $date = null): ?float
    {
        $month = $this->monthHours($date);

        return $month > 0 ? round($hours / $month, 2) : null;
    }
}
