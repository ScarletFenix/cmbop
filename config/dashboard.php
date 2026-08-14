<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin dashboard metrics cache
    |--------------------------------------------------------------------------
    |
    | Seconds to remember KPI / trend / action-queue / finance JSON. 0 (default)
    | is off so local and tests always see live numbers. Queue-count badges are
    | never cached (the nav polls them every 60s). Set DASHBOARD_METRICS_CACHE=30
    | in production if the dashboard endpoints start to hurt.
    |
    */
    'metrics_cache_seconds' => max(0, (int) env('DASHBOARD_METRICS_CACHE', 0)),

];
