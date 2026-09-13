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

    /*
    | Max .docx files accepted in one upload-modal session. Each file still
    | posts to the existing single-file chunked endpoint.
    */
    'multi_upload_limit' => max(1, min(20, (int) env('LIBRARY_MULTI_UPLOAD_LIMIT', 10))),
];
