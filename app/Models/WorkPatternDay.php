<?php

namespace App\Models;

use App\Enums\Weekday;
use Database\Factories\WorkPatternDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $work_pattern_id
 * @property Weekday $weekday
 * @property Carbon|null $start_time
 * @property Carbon|null $end_time
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPattern $workPattern
 */
#[Fillable(['weekday', 'start_time', 'end_time'])]
class WorkPatternDay extends Model
{
    /** @use HasFactory<WorkPatternDayFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
        ];
    }

    /**
     * Get the pattern this day belongs to.
     *
     * @return BelongsTo<WorkPattern, $this>
     */
    public function workPattern(): BelongsTo
    {
        return $this->belongsTo(WorkPattern::class);
    }

    /**
     * Determine whether any hours are worked on this day.
     */
    public function isWorked(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    /**
     * Get the hours worked on this day.
     */
    public function hours(): float
    {
        if (! $this->isWorked()) {
            return 0.0;
        }

        return round($this->start_time->diffInMinutes($this->end_time) / 60, 2);
    }
}
