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
    | Set 'annual_leave_hours' to a figure to use that instead of the
    | calculation, for when the awarded number is known and should be trusted
    | over anything derived. Any public holiday surplus is added on top of
    | whichever figure is used.
    |
    */

    'annual_leave_days' => 27,

    'annual_leave_hours' => null,

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
    | Carryover
    |--------------------------------------------------------------------------
    |
    | The most that may be carried from one leave year into the next. Anything
    | above it is lost at the turn of the year. Set to null for no cap.
    |
    */

    'carryover_cap_hours' => null,

];
