<?php

namespace App\Support;

/**
 * Formats decimal hours as hours and minutes, which is how people read time.
 */
class Duration
{
    /**
     * Format a number of hours as "7h 30m", "45m", or "7h".
     *
     * A duration of nothing reads "0h", so a figure is always shown.
     */
    public static function hours(float $hours): string
    {
        $minutes = (int) round(abs($hours) * 60);

        $whole = intdiv($minutes, 60);
        $part = $minutes % 60;

        if ($minutes === 0) {
            return '0h';
        }

        if ($whole === 0) {
            return "{$part}m";
        }

        return $part === 0 ? "{$whole}h" : "{$whole}h {$part}m";
    }

    /**
     * Format a number of hours with its direction, as "+1h 15m" or "-30m".
     *
     * The sign follows the minutes the figure rounds to, so a hair over zero
     * never reads as "+0h".
     */
    public static function signed(float $hours): string
    {
        $formatted = self::hours($hours);

        if ($formatted === '0h') {
            return $formatted;
        }

        return ($hours > 0 ? '+' : '-').$formatted;
    }
}
