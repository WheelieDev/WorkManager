<?php

namespace App\Models;

use Database\Factories\WorkPatternFactory;
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
 * @property int $id
 * @property Carbon $start_date
 * @property array<int, int> $months
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WorkPatternDay> $days
 */
#[Fillable(['start_date', 'months'])]
class WorkPattern extends Model
{
    /** @use HasFactory<WorkPatternFactory> */
    use HasFactory;

    /**
     * The date the pattern takes effect.
     *
     * Stored without a time component so date-only comparisons in SQL stay
     * correct, including for a pattern starting today.
     *
     * @return Attribute<Carbon, string>
     */
    protected function startDate(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (DateTimeInterface|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'months' => 'array',
        ];
    }

    /**
     * Determine whether this pattern works the given month of the year.
     *
     * A pattern that names no months works all of them, so a pattern created
     * before months existed keeps behaving as it did.
     */
    public function worksMonth(int $month): bool
    {
        $months = $this->months ?? [];

        return $months === [] || in_array($month, $months, strict: false);
    }

    /**
     * Get the months of the year this pattern does not work.
     *
     * @return array<int, int>
     */
    public function monthsOff(): array
    {
        return array_values(array_filter(
            range(1, 12),
            fn (int $month): bool => ! $this->worksMonth($month),
        ));
    }

    /**
     * Get the days that make up this pattern.
     *
     * @return HasMany<WorkPatternDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(WorkPatternDay::class)->orderBy('weekday');
    }

    /**
     * Get the pattern in effect on the given date, if any.
     */
    public static function inEffectOn(DateTimeInterface $date): ?self
    {
        return self::query()
            ->where('start_date', '<=', Carbon::parse($date)->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * Scope a query to patterns that have already taken effect.
     *
     * @param  Builder<WorkPattern>  $query
     */
    public function scopeStarted(Builder $query): void
    {
        $query->where('start_date', '<=', now()->toDateString());
    }

    /**
     * Determine whether this pattern is the one currently in effect.
     */
    public function isCurrent(): bool
    {
        return self::inEffectOn(now())?->is($this) ?? false;
    }

    /**
     * Get the total hours worked across the week.
     */
    public function weeklyHours(): float
    {
        return round($this->days->sum(fn (WorkPatternDay $day) => $day->hours()), 2);
    }
}
