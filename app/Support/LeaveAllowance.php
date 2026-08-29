<?php

namespace App\Support;

use App\Enums\LeaveType;

/**
 * What one kind of absence is worth this leave year, and what is left of it.
 *
 * An allowance of null means the type is recorded but not capped, which is the
 * honest position until an employer sets a figure.
 */
class LeaveAllowance
{
    public function __construct(
        public readonly LeaveType $type,
        public readonly ?float $entitlement,
        public readonly float $used,
    ) {}

    /**
     * Get what is left of the allowance, or null where there is no limit.
     */
    public function remaining(): ?float
    {
        return $this->entitlement === null ? null : round($this->entitlement - $this->used, 2);
    }

    /**
     * Determine whether this type has a limit to measure usage against.
     */
    public function isCapped(): bool
    {
        return $this->entitlement !== null;
    }

    /**
     * Determine whether more has been taken than the allowance covers.
     */
    public function isOverdrawn(): bool
    {
        return ($this->remaining() ?? 0.0) < 0;
    }

    /**
     * Determine whether this is worth showing at all.
     *
     * An uncapped type nobody has used is noise, not information.
     */
    public function isWorthShowing(): bool
    {
        return $this->isCapped() || $this->used > 0;
    }
}
