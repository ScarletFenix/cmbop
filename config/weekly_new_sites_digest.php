<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Weekly personalised new-site digest
    |--------------------------------------------------------------------------
    |
    | Advertisers who paid for a placement in a niche+country pair receive a
    | weekly email of newly catalogued sites in those same pairs.
    |
    | Timing: Monday 10:00 in Asia/Karachi (PKT, UTC+5, no DST). The scheduler
    | entry in bootstrap/app.php uses ->timezone('Asia/Karachi') so this is
    | independent of APP timezone (UTC). Equivalent UTC clock: Monday 05:00.
    |
    | Feature default is ON (email_notifications.types.weekly_new_sites
    | default_enabled=true). Admin → Email Center or Admin → Weekly new sites
    | can turn only this type off. Unrelated reminders stay unchanged.
    |
    */

    'timezone' => env('WEEKLY_NEW_SITES_TZ', 'Asia/Karachi'),

    'weekday' => (int) env('WEEKLY_NEW_SITES_WEEKDAY', 1), // 1 = Monday (Laravel weeklyOn)

    'time' => env('WEEKLY_NEW_SITES_TIME', '10:00'),

    'min_sites' => max(1, (int) env('WEEKLY_NEW_SITES_MIN', 2)),

    'max_sites' => max(1, (int) env('WEEKLY_NEW_SITES_MAX', 5)),

    /*
    | After a click, paid orders for the same site are counted as "ordered
    | after click" inside this window. The report never claims the email
    | caused the order.
    */
    'attribution_window_days' => max(1, (int) env('WEEKLY_NEW_SITES_ATTRIBUTION_DAYS', 14)),

    'unsubscribe_expire_days' => max(1, (int) env('WEEKLY_NEW_SITES_UNSUB_DAYS', 30)),

    'recipient_limit' => max(1, (int) env('WEEKLY_NEW_SITES_RECIPIENT_LIMIT', 500)),

];
