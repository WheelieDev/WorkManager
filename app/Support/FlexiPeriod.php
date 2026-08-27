<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A six week flexi period, derived from a fixed anchor date.
 *
 * Periods are pure arithmetic rather than database rows, so there is nothing
 * to keep in sync with the rule.
 */
class FlexiPeriod
{
    public readonly Carbon $start;

    public readonly Carbon $end;

    /**
     * @param  int  $index  Periods since the anchor. Negative before it.
     */
    public function __construct(public readonly int $index)
    {
        $this->start = self::anchor()->addDays($index * self::length())->startOfDay();
        $this->end = $this->start->copy()->addDays(self::length() - 1)->endOfDay();
    }

    /**
     * Get the anchor date, which is day one of period zero.
     */
    public static function anchor(): Carbon
    {
        return Carbon::parse(config('flexi.anchor_date'))->startOfDay();
    }

    /**
     * Get the number of days in a period.
     */
    public static function length(): int
    {
        return (int) config('flexi.period_days');
    }

    /**
     * Get the hour limit that the balance is measured against.
     */
    public static function limit(): float
    {
        return (float) config('flexi.limit_hours');
    }

    /**
     * Get the period containing the given date.
     */
    public static function forDate(DateTimeInterface $date): self
    {
        $days = self::anchor()->diffInDays(Carbon::parse($date)->startOfDay(), false);

        return new self((int) floor($days / self::length()));
    }

    /**
     * Get the period containing today.
     */
    public static function current(): self
    {
        return self::forDate(now());
    }

    /**
     * Get the period after this one.
     */
    public function next(): self
    {
        return new self($this->index + 1);
    }

    /**
     * Get the period before this one.
     */
    public function previous(): self
    {
        return new self($this->index - 1);
    }

    /**
     * Determine whether the given date falls inside this period.
     */
    public function contains(DateTimeInterface $date): bool
    {
        return Carbon::parse($date)->betweenIncluded($this->start, $this->end);
    }

    /**
     * Determine whether this is the period we are currently in.
     */
    public function isCurrent(): bool
    {
        return $this->contains(now());
    }

    /**
     * Determine whether this period has finished.
     */
    public function hasClosed(): bool
    {
        return now()->greaterThan($this->end);
    }

    /**
     * Get the last date in this period that has actually happened.
     *
     * Future days must not count towards the balance, or every period would
     * open hundreds of hours in deficit.
     */
    public function countedThrough(): ?Carbon
    {
        if (now()->lessThan($this->start)) {
            return null;
        }

        return $this->hasClosed() ? $this->end->copy()->startOfDay() : Carbon::parse(now())->startOfDay();
    }

    /**
     * Get every date in this period.
     *
     * @return Collection<int, Carbon>
     */
    public function dates(): Collection
    {
        return collect(range(0, self::length() - 1))
            ->map(fn (int $offset): Carbon => $this->start->copy()->addDays($offset));
    }

    /**
     * Get the Monday starting each week of this period.
     *
     * @return Collection<int, Carbon>
     */
    public function weekStarts(): Collection
    {
        return collect(range(0, $this->weekCount() - 1))
            ->map(fn (int $week): Carbon => $this->start->copy()->addWeeks($week));
    }

    /**
     * Get the number of weeks in this period.
     */
    public function weekCount(): int
    {
        return (int) ceil(self::length() / 7);
    }

    /**
     * Get the one based week number containing the given date.
     */
    public function weekNumberFor(DateTimeInterface $date): int
    {
        return (int) floor($this->start->diffInDays(Carbon::parse($date)->startOfDay(), false) / 7) + 1;
    }

    /**
     * Get a readable label for the period, such as "10 Aug - 20 Sep 2026".
     */
    public function label(): string
    {
        return $this->start->format('j M').' - '.$this->end->format('j M Y');
    }
}
