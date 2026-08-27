<?php

namespace App\Enums;

enum Weekday: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;

    /**
     * Get the full label for the weekday.
     */
    public function label(): string
    {
        return $this->name;
    }

    /**
     * Get the three letter abbreviation for the weekday.
     */
    public function abbreviation(): string
    {
        return substr($this->name, 0, 3);
    }
}
