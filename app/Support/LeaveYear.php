<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A leave year, running from the configured turnover date.
 */
class LeaveYear
{
    public readonly Carbon $start;

    public readonly Carbon $end;

    /**
     * @param  int  $year  The calendar year the leave year starts in.
     */
    public function __construct(public readonly int $year)
    {
        [$month, $day] = array_map('intval', explode('-', (string) config('leave.year_starts')));

        $this->start = Carbon::create($year, $month, $day)->startOfDay();
        $this->end = $this->start->copy()->addYear()->subDay()->endOfDay();
    }

    /**
     * Get the leave year containing the given date.
     */
    public static function forDate(DateTimeInterface $date): self
    {
        $date = Carbon::parse($date)->startOfDay();
        $candidate = new self((int) $date->year);

        return $date->lessThan($candidate->start) ? new self((int) $date->year - 1) : $candidate;
    }

    /**
     * Get the leave year containing today.
     */
    public static function current(): self
    {
        return self::forDate(now());
    }

    /**
     * Get the leave year after this one.
     */
    public function next(): self
    {
        return new self($this->year + 1);
    }

    /**
     * Get the leave year before this one.
     */
    public function previous(): self
    {
        return new self($this->year - 1);
    }

    /**
     * Determine whether the given date falls inside this leave year.
     */
    public function contains(DateTimeInterface $date): bool
    {
        return Carbon::parse($date)->betweenIncluded($this->start, $this->end);
    }

    /**
     * Determine whether this is the leave year we are currently in.
     */
    public function isCurrent(): bool
    {
        return $this->contains(now());
    }

    /**
     * Get a readable label, such as "1 Apr 2026 - 31 Mar 2027".
     */
    public function label(): string
    {
        return $this->start->format('j M Y').' - '.$this->end->format('j M Y');
    }
}
