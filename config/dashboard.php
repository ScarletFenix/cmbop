<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin dashboard metrics cache
    |--------------------------------------------------------------------------
    |
    | Seconds to remember trend / distribution JSON and the non-queue KPI
    | totals (users, GMV, sites). 0 (default) is off. Queue-count badges,
    | action-queue rows, the finance strip, and the attention fields on
    | statistics stay live so they cannot disagree with the work lists.
    | Set DASHBOARD_METRICS_CACHE=30 in production if the chart endpoints
    | start to hurt.
    |
    */
    'metrics_cache_seconds' => max(0, (int) env('DASHBOARD_METRICS_CACHE', 0)),

];
