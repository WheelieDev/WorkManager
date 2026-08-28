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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Leave booked against a single date, in hours so part days are natural.
 *
 * @property int $id
 * @property Carbon $date
 * @property LeaveType $type
 * @property float $hours
 * @property int|null $leave_request_id
 */
#[Fillable(['date', 'type', 'hours', 'leave_request_id'])]
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
     * Get the request this day was booked under.
     *
     * @return BelongsTo<LeaveRequest, $this>
     */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
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
