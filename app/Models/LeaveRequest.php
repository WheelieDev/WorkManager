<?php

namespace App\Models;

use Database\Factories\LeaveRequestFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A block of leave as it was asked for, rather than the days it became.
 *
 * @property int $id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property-read Collection<int, LeaveDay> $days
 */
#[Fillable(['starts_on', 'ends_on'])]
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /**
     * The first day asked for.
     *
     * @return Attribute<Carbon, string>
     */
    protected function startsOn(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (DateTimeInterface|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * The last day asked for.
     *
     * @return Attribute<Carbon, string>
     */
    protected function endsOn(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (DateTimeInterface|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * Get the days this request booked.
     *
     * @return HasMany<LeaveDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(LeaveDay::class)->orderBy('date');
    }

    /**
     * Scope a query to requests overlapping two dates.
     *
     * @param  Builder<LeaveRequest>  $query
     */
    public function scopeOverlapping(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->where('starts_on', '<=', Carbon::parse($to)->toDateString())
            ->where('ends_on', '>=', Carbon::parse($from)->toDateString());
    }

    /**
     * Determine whether the request covers a single day.
     */
    public function isSingleDay(): bool
    {
        return $this->starts_on->isSameDay($this->ends_on);
    }

    /**
     * Get a readable label for the dates asked for.
     */
    public function label(): string
    {
        if ($this->isSingleDay()) {
            return $this->starts_on->format('D j M Y');
        }

        $sameYear = $this->starts_on->year === $this->ends_on->year;

        return $this->starts_on->format($sameYear ? 'D j M' : 'D j M Y')
            .' to '.$this->ends_on->format('D j M Y');
    }
}
