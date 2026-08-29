<?php

namespace App\Enums;

/**
 * What a stretch of time at work is being tracked as.
 *
 * Contracted hours answer to the working pattern and settle in the flexi
 * balance. Time off in lieu is work done on the understanding it is given back
 * as time off later, so it is kept out of the flexi balance entirely and banked
 * in its own pot instead.
 */
enum WorkKind: string
{
    case Contracted = 'contracted';

    case Lieu = 'lieu';

    /**
     * Get the label for the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Contracted => 'Contracted hours',
            self::Lieu => 'Time off in lieu',
        };
    }

    /**
     * Determine whether this kind settles in the flexi balance.
     */
    public function countsTowardsFlexi(): bool
    {
        return $this === self::Contracted;
    }

    /**
     * Get the short label used where space is tight.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Contracted => 'Contracted',
            self::Lieu => 'In lieu',
        };
    }
}
