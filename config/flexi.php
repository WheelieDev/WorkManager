<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flexi Period Anchor
    |--------------------------------------------------------------------------
    |
    | Day one of a known period. Every other period is derived from this date,
    | forwards and backwards, so there is no period table to keep in sync.
    | This must be a Monday.
    |
    */

    'anchor_date' => '2026-08-10',

    /*
    |--------------------------------------------------------------------------
    | Period Length
    |--------------------------------------------------------------------------
    |
    | The number of days in a period. Six weeks, running Monday to Sunday.
    |
    */

    'period_days' => 42,

    /*
    |--------------------------------------------------------------------------
    | Flexi Limit
    |--------------------------------------------------------------------------
    |
    | The number of hours the balance may reach in either direction before it
    | is flagged. Recording real hours is never blocked by this. At period
    | close it also caps how much positive balance carries forward; negative
    | balances carry in full.
    |
    */

    'limit_hours' => 16,

    /*
    |--------------------------------------------------------------------------
    | Bank Holidays
    |--------------------------------------------------------------------------
    |
    | Bank holidays are pulled from the gov.uk feed by the bank-holidays:sync
    | command. A bank holiday expects no hours, so not working one does not
    | count against the balance.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Tracking Start
    |--------------------------------------------------------------------------
    |
    | The first date the balance counts. Days before it are shown, and hours
    | may still be recorded on them, but they neither owe nor bank anything,
    | so time before you started keeping a timesheet is not held against you.
    | The periods and dates themselves still exist, ready for annual leave.
    |
    | Set to null to count everything.
    |
    */

    'tracking_starts' => '2026-09-01',

    'bank_holidays_url' => 'https://www.gov.uk/bank-holidays.json',

    'bank_holidays_division' => 'england-and-wales',

];
