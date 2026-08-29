<?php

namespace App\Enums;

/**
 * The kinds of absence that can be booked against a day.
 *
 * A day may hold one entry of each type, so a hospital appointment in the
 * morning and annual leave in the afternoon are two rows, not a choice.
 */
enum LeaveType: string
{
    case Annual = 'annual';

    case Disability = 'disability';

    case Hospital = 'hospital';

    case Compassionate = 'compassionate';

    case PhasedReturn = 'phased-return';

    case Volunteering = 'volunteering';

    case Sickness = 'sickness';

    case Lieu = 'lieu';

    case Unpaid = 'unpaid';

    /**
     * Get the label for the leave type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Annual => 'Annual leave',
            self::Disability => 'Disability leave',
            self::Hospital => 'Hospital appointment',
            self::Compassionate => 'Compassionate leave',
            self::PhasedReturn => 'Phased return',
            self::Volunteering => 'Volunteering leave',
            self::Sickness => 'Sickness',
            self::Lieu => 'Time off in lieu',
            self::Unpaid => 'Unpaid or other',
        };
    }

    /**
     * Determine whether this type draws on the annual leave balance.
     *
     * Only annual leave does. Everything else is absence the employer grants
     * separately, so booking a hospital appointment must not cost a holiday.
     */
    public function drawsOnAnnualLeave(): bool
    {
        return $this === self::Annual;
    }

    /**
     * Determine whether this type draws on the time off in lieu balance.
     *
     * Lieu is banked by working it rather than awarded for the year, so it has
     * a running balance of its own instead of an entitlement.
     */
    public function drawsOnLieu(): bool
    {
        return $this === self::Lieu;
    }

    /**
     * Determine whether this type is measured by the sickness allowance.
     *
     * Sickness is counted in months of pay against length of service over a
     * rolling year, not in days against a leave year, so it keeps its own
     * accounting rather than borrowing the day-based allowance below.
     */
    public function usesSicknessAllowance(): bool
    {
        return $this === self::Sickness;
    }

    /**
     * Get the full time allowance for this type, in days.
     *
     * Null means the type is recorded but not capped, so usage is still shown
     * without inventing a limit nobody agreed.
     */
    public function allowanceDays(): ?float
    {
        $days = config("leave.types.{$this->value}.days");

        return $days === null ? null : (float) $days;
    }

    /**
     * Determine whether this type's allowance shrinks with contracted weeks.
     */
    public function proratesByWeeks(): bool
    {
        return (bool) config("leave.types.{$this->value}.prorate_by_weeks", false);
    }

    /**
     * Determine whether this type has an allowance to measure usage against.
     */
    public function hasAllowance(): bool
    {
        return $this->allowanceDays() !== null;
    }

    /**
     * Get the types that can be booked, annual leave first.
     *
     * @return array<int, self>
     */
    public static function bookable(): array
    {
        return self::cases();
    }

    /**
     * Get the types that do not draw on the annual leave balance.
     *
     * Sickness and time off in lieu keep their own accounting, so neither
     * belongs among the types measured in days against a leave year.
     *
     * @return array<int, self>
     */
    public static function separate(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => ! $type->drawsOnAnnualLeave()
                && ! $type->usesSicknessAllowance()
                && ! $type->drawsOnLieu(),
        ));
    }
}
