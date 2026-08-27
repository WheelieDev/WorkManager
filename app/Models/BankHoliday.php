<?php

namespace App\Models;

use Database\Factories\BankHolidayFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An England and Wales bank holiday, taken from the gov.uk feed.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $title
 * @property string|null $notes
 */
#[Fillable(['date', 'title', 'notes'])]
class BankHoliday extends Model
{
    /** @use HasFactory<BankHolidayFactory> */
    use HasFactory;

    /**
     * The date of the holiday.
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
     * Get every bank holiday date, keyed by date string.
     *
     * @return array<string, string>
     */
    public static function titlesByDate(): array
    {
        return self::query()
            ->pluck('title', 'date')
            ->mapWithKeys(fn (string $title, string $date): array => [
                Carbon::parse($date)->toDateString() => $title,
            ])
            ->all();
    }
}
