<?php

namespace App\Models;

use Database\Factories\LeaveCarryoverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Leave carried into a year by hand, overriding whatever would be worked out
 * from the year before.
 *
 * @property int $id
 * @property int $leave_year
 * @property float $hours
 */
#[Fillable(['leave_year', 'hours'])]
class LeaveCarryover extends Model
{
    /** @use HasFactory<LeaveCarryoverFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hours' => 'float',
        ];
    }
}
