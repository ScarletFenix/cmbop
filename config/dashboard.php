<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin dashboard metrics cache
    |--------------------------------------------------------------------------
    |
    | Seconds to remember KPI / trend / queue JSON. 0 (default) is off so local
    | and tests always see live numbers. Set DASHBOARD_METRICS_CACHE=30 in
    | production if the dashboard endpoints start to hurt.
    |
    */
    'metrics_cache_seconds' => max(0, (int) env('DASHBOARD_METRICS_CACHE', 0)),

];
