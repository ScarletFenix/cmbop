<?php

return [
    /*
    | Kill switch: set LIBRARY_LIVE_SEARCH=false to fall back to classic
    | full-page admin library navigation and 404 the fragment endpoint.
    */
    'live_search' => [
        'enabled' => filter_var(env('LIBRARY_LIVE_SEARCH', true), FILTER_VALIDATE_BOOL),
    ],

    'bulk_limit' => 50,
];
