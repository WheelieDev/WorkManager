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
    | Annual Leave
    |--------------------------------------------------------------------------
    |
    | Bookable leave for a full leave year, in hours, as awarded. This is the
    | figure after the public holiday share has already been taken out, so it
    | is entered directly rather than derived.
    |
    */

    'annual_leave_hours' => 84.5,

    /*
    |--------------------------------------------------------------------------
    | Public Holidays
    |--------------------------------------------------------------------------
    |
    | The full time public holiday allowance in days, pro-rata'd by pattern.
    | Bank holidays falling on a working day draw from this pot; once it is
    | empty the rest comes out of bookable leave, which is the number a part
    | time worker actually wants to see.
    |
    */

    'public_holiday_days' => 8,

];
