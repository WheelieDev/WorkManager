<?php

namespace App\Models;

use App\Enums\LeaveType;
use Database\Factories\LeaveDayFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Leave booked against a single date, in hours so part days are natural.
 *
 * @property int $id
 * @property Carbon $date
 * @property LeaveType $type
 * @property float $hours
 */
#[Fillable(['date', 'type', 'hours'])]
class LeaveDay extends Model
{
    /** @use HasFactory<LeaveDayFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'hours' => 'float',
        ];
    }

    /**
     * The date the leave is taken.
     *
     * Stored date-only so date comparisons in SQL stay correct.
     *
     * @return Attribute<Carbon, string>
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (DateTimeInterface|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * Scope a query to leave falling between two dates.
     *
     * @param  Builder<LeaveDay>  $query
     */
    public function scopeBetweenDates(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->whereBetween('date', [
            Carbon::parse($from)->toDateString(),
            Carbon::parse($to)->toDateString(),
        ]);
    }
}
