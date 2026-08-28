<?php

namespace App\Enums;

enum LeaveType: string
{
    case Annual = 'annual';

    /**
     * Get the label for the leave type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Annual => 'Annual leave',
        };
    }
}
