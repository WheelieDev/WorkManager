<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Full Time Reference
    |--------------------------------------------------------------------------
    |
    | What a full time week and year look like. Entitlements are held as full
    | time days and pro-rata'd by the hours the working pattern actually
    | works, so a part time week draws a proportionate share.
    |
    | 52.14 is 365 / 7. The leap year figure is 52.18, which is not used here.
    |
    */

    'full_time_weekly_hours' => 37.5,

    'weeks_per_year' => 52.14,

    /*
    |--------------------------------------------------------------------------
    | Leave Year
    |--------------------------------------------------------------------------
    |
    | The day and month the leave year turns over. 1 April means the year runs
    | to 31 March.
    |
    */

    'year_starts' => '04-01',

    /*
    |--------------------------------------------------------------------------
    | Contracted Weeks
    |--------------------------------------------------------------------------
    |
    | The weeks of the year actually paid. Both entitlements are pro-rata'd to
    | match, since weeks not under contract are weeks of entitlement not
    | earned. Set to null to use the whole year.
    |
    */

    'contracted_weeks' => 47.8,

    /*
    |--------------------------------------------------------------------------
    | Annual Leave
    |--------------------------------------------------------------------------
    |
    | Bookable leave for a full leave year, as full time days, pro-rata'd by
    | the hours the pattern works and the weeks under contract.
    |
    | Set 'annual_leave_hours' to the awarded figure to use that instead of the
    | calculation. An awarded figure is what the employer settled on, so the
    | public holiday adjustment is already inside it and is not applied again;
    | it is still shown, so the working can be checked.
    |
    */

    'annual_leave_days' => 27,

    'annual_leave_hours' => 84.5,

    /*
    |--------------------------------------------------------------------------
    | Public Holidays
    |--------------------------------------------------------------------------
    |
    | The full time public holiday allowance in days, pro-rata'd by pattern and
    | contracted weeks. Bank holidays falling on a working day draw from this
    | pot. Whatever is left over is added to bookable leave, and if the pot
    | runs out the rest comes off it instead. Either way a part time worker can
    | see what their working days cost them.
    |
    */

    'public_holiday_days' => 8,

    /*
    |--------------------------------------------------------------------------
    | Pro-rata Public Holidays By Weeks
    |--------------------------------------------------------------------------
    |
    | Whether the public holiday allowance is reduced for weeks not paid, as
    | annual leave is. Bank holidays fall when they fall, so many employers
    | give the full hours-based share regardless of contracted weeks, and that
    | is the reading that matches the awarded figure here.
    |
    */

    'prorate_public_holidays' => false,

    /*
    |--------------------------------------------------------------------------
    | Other Absence Types
    |--------------------------------------------------------------------------
    |
    | Absence that is granted separately from annual leave, so booking it never
    | costs a holiday. Each type may carry its own allowance in full time days,
    | pro-rata'd by the hours the pattern works, exactly as annual leave is.
    |
    | A type with a null allowance is still recorded and totalled; it simply has
    | no limit to measure against, which is the honest position until an
    | employer sets one. Set 'prorate_by_weeks' where an allowance should also
    | shrink for weeks not under contract.
    |
    */

    'types' => [

        'volunteering' => [
            'days' => 1,
            'prorate_by_weeks' => false,
        ],

        'disability' => [
            'days' => null,
        ],

        'hospital' => [
            'days' => null,
        ],

        'compassionate' => [
            'days' => null,
        ],

        'phased-return' => [
            'days' => null,
        ],

        'unpaid' => [
            'days' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Time Off In Lieu
    |--------------------------------------------------------------------------
    |
    | The lieu owed before any of it was tracked here. Everything after that is
    | earned by tracking work as lieu rather than as contracted hours, and spent
    | by booking it as leave, so the pot is a running balance rather than a
    | yearly award. A figure entered on the leave page wins over this one.
    |
    */

    'lieu_opening_hours' => 0,

    /*
    |--------------------------------------------------------------------------
    | Length of Service
    |--------------------------------------------------------------------------
    |
    | The day continuous service began. Sickness pay is banded by how long that
    | service has run, so without it no sickness entitlement can be worked out
    | and the page says so rather than guessing.
    |
    */

    'service_started' => env('SERVICE_STARTED'),

    /*
    |--------------------------------------------------------------------------
    | Sickness Allowance
    |--------------------------------------------------------------------------
    |
    | Occupational sick pay, in months of pay, banded by completed months of
    | service. The first band that the service length has not yet passed is the
    | one that applies, so the bands are read from the longest down.
    |
    | The default bands are the policy as written: one month's full pay in the
    | first year, with two months' half pay only after four months' service,
    | rising to six and six after five years.
    |
    | Entitlement is reduced by the sickness taken in the twelve months
    | immediately before the first day of the absence, which makes this a
    | rolling window rather than anything to do with the leave year.
    |
    */

    'sickness' => [

        'bands' => [
            ['after_months' => 0, 'full_pay_months' => 1, 'half_pay_months' => 0],
            ['after_months' => 4, 'full_pay_months' => 1, 'half_pay_months' => 2],
            ['after_months' => 12, 'full_pay_months' => 2, 'half_pay_months' => 2],
            ['after_months' => 24, 'full_pay_months' => 4, 'half_pay_months' => 4],
            ['after_months' => 36, 'full_pay_months' => 5, 'half_pay_months' => 5],
            ['after_months' => 60, 'full_pay_months' => 6, 'half_pay_months' => 6],
        ],

        'lookback_months' => 12,

    ],

    /*
    |--------------------------------------------------------------------------
    | Carryover
    |--------------------------------------------------------------------------
    |
    | The most that may be carried from one leave year into the next. Anything
    | above it is lost at the turn of the year. Set to null for no cap.
    |
    */

    'carryover_cap_hours' => null,

];
