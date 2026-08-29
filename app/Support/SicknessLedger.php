<?php

namespace App\Support;

use App\Enums\LeaveType;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Setting;
use App\Models\WorkPattern;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The day the absence begins, which every figure here is worked out from.
     */
    public readonly Carbon $asAt;

    /**
     * @param  DateTimeInterface|string|null  $asAt  The first day of the absence, or today.
     */
    public function __construct(DateTimeInterface|string|null $asAt = null)
    {
        $this->calculator = new FlexiCalculator;
        $this->asAt = Carbon::parse($asAt ?? now())->startOfDay();
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
    public function monthsOfService(): ?int
    {
        $started = $this->serviceStarted();

        if ($started === null) {
            return null;
        }

        return $this->asAt->lessThan($started) ? 0 : (int) $started->diffInMonths($this->asAt);
    }

    /**
     * Get the completed years of service as at a date.
     */
    public function yearsOfService(): ?int
    {
        $months = $this->monthsOfService();

        return $months === null ? null : intdiv($months, 12);
    }

    /**
     * Get the year of service a date falls in.
     *
     * The policy bands absence by the year being served rather than by the
     * years completed, so somebody three years in is in their fourth year.
     */
    public function yearOfService(): ?int
    {
        $years = $this->yearsOfService();

        return $years === null ? null : $years + 1;
    }

    /**
     * Say which year of service a date falls in, as the policy words it.
     */
    public function yearOfServiceLabel(): ?string
    {
        $year = $this->yearOfService();

        return $year === null ? null : Number::ordinal($year).' year of service';
    }

    /**
     * Get the band that applies on the first day of an absence.
     *
     * The longest band the service has reached wins, so a policy expressed as
     * "after five years" needs no special case.
     */
    public function band(): ?SicknessBand
    {
        $months = $this->monthsOfService();

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
    public function monthHours(): float
    {
        $weekly = WorkPattern::inEffectOn($this->asAt)?->weeklyHours() ?? 0.0;
        $weeksPerMonth = (float) config('leave.weeks_per_year') / 12;

        return round($weekly * $weeksPerMonth, 2);
    }

    /**
     * Get the full pay entitlement in hours, before anything already taken.
     */
    public function fullPayEntitlement(): float
    {
        $band = $this->band();

        return $band === null ? 0.0 : round($band->fullPayMonths * $this->monthHours(), 2);
    }

    /**
     * Get the half pay entitlement in hours, before anything already taken.
     */
    public function halfPayEntitlement(): float
    {
        $band = $this->band();

        return $band === null ? 0.0 : round($band->halfPayMonths * $this->monthHours(), 2);
    }

    /**
     * Get the first day of the rolling window that reduces the entitlement.
     */
    public function windowStart(): Carbon
    {
        $months = (int) (config('leave.sickness.lookback_months') ?? 12);

        return $this->asAt->copy()->subMonths($months);
    }

    /**
     * Get the last day the rolling window counts.
     *
     * The window runs up to but not including the day the absence begins.
     */
    public function windowEnd(): Carbon
    {
        return $this->asAt->copy()->subDay();
    }

    /**
     * Describe the rolling window, which is what makes this unlike a leave year.
     */
    public function windowLabel(): string
    {
        return $this->windowStart()->format('j M Y').' - '.$this->windowEnd()->format('j M Y');
    }

    /**
     * Get the sickness taken in the twelve months before an absence begins.
     *
     * The day itself is excluded: the policy counts what came before it.
     *
     * @return Collection<int, LeaveDay>
     */
    public function absencesBefore(): Collection
    {
        return LeaveDay::query()
            ->where('type', LeaveType::Sickness)
            ->where('date', '>=', $this->windowStart()->toDateString())
            ->where('date', '<', $this->asAt->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Get the sickness requests the window counts, newest first.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function requests(): Collection
    {
        return LeaveRequest::query()
            ->with('days')
            ->whereHas('days', fn (Builder $days) => $days
                ->where('type', LeaveType::Sickness)
                ->where('date', '>=', $this->windowStart()->toDateString())
                ->where('date', '<', $this->asAt->toDateString()))
            ->orderByDesc('starts_on')
            ->get();
    }

    /**
     * Get the hours of sickness counted against the entitlement.
     */
    public function used(): float
    {
        return round(
            $this->absencesBefore()->sum(fn (LeaveDay $day): float => $this->effectiveHours($day)),
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
    public function fullPayRemaining(): float
    {
        return round(max(0, $this->fullPayEntitlement() - $this->used()), 2);
    }

    /**
     * Get the half pay still available, once full pay has been exhausted.
     */
    public function halfPayRemaining(): float
    {
        $beyondFullPay = max(0, $this->used() - $this->fullPayEntitlement());

        return round(max(0, $this->halfPayEntitlement() - $beyondFullPay), 2);
    }

    /**
     * Determine whether the entitlement has run out entirely.
     */
    public function isExhausted(): bool
    {
        return $this->fullPayRemaining() <= 0 && $this->halfPayRemaining() <= 0;
    }

    /**
     * Express hours as months of pay at the pattern in effect.
     */
    public function asMonths(float $hours): ?float
    {
        $month = $this->monthHours();

        return $month > 0 ? round($hours / $month, 2) : null;
    }
}
