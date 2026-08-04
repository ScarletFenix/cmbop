<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publisher domain visibility
    |--------------------------------------------------------------------------
    |
    | Catalog listings show a partially masked domain until the advertiser opens
    | it. The point is not secrecy — anyone allowed to evaluate a site before
    | buying will end up knowing the domain — it is to stop a competitor
    | harvesting the whole inventory, and to leave a trail when a publisher
    | reports being approached directly.
    |
    | Browsing and opening addresses are UNLIMITED. An agency planning a campaign
    | may legitimately work through hundreds of listings, and a quota punishes
    | exactly that person while barely inconveniencing a scraper who can register
    | again. Volume is not the tell. Pace is.
    |
    */
    'url_reveal' => [

        /*
        | Pace, not quota.
        |
        | Every threshold below describes a rate a person cannot sustain. They
        | are starting points: run with `enforce` off for a couple of weeks,
        | look at what your real users actually do, and set these at the far
        | tail of that distribution. Numbers picked by guesswork are how you
        | throttle your best customer on a Friday afternoon.
        */
        'pace' => [

            /*
            | Off means detect and report but never restrict. Use it to
            | calibrate before anything can bite a real buyer.
            */
            'enforce' => filter_var(env('CATALOG_PACE_ENFORCE', true), FILTER_VALIDATE_BOOL),

            /*
            | Faster than this and the next address is asked to wait. A person
            | barely notices; a script's yield collapses. Nothing is refused.
            */
            'slow_after' => (int) env('CATALOG_PACE_SLOW_AFTER', 60),
            'slow_window_minutes' => (int) env('CATALOG_PACE_SLOW_WINDOW', 5),
            'slow_retry_seconds' => (int) env('CATALOG_PACE_SLOW_RETRY', 3),

            /*
            | Sustained at this rate, new addresses stop until the window
            | clears. Browsing, filtering and everything already opened keep
            | working — only further disclosure pauses.
            */
            'freeze_after' => (int) env('CATALOG_PACE_FREEZE_AFTER', 250),
            'freeze_window_minutes' => (int) env('CATALOG_PACE_FREEZE_WINDOW', 30),

            /*
            | Volume worth a human glance. Nothing changes for the user — most
            | of these will be real buyers, and that is how you learn what
            | normal looks like here.
            */
            'review_after' => (int) env('CATALOG_PACE_REVIEW_AFTER', 300),
            'review_window_hours' => (int) env('CATALOG_PACE_REVIEW_WINDOW', 24),

            /*
            | Humans are irregular: they pause, re-read, get distracted. Scripts
            | are metronomic. Once there are enough samples, a near-constant gap
            | between requests is the most reliable tell there is, and evading it
            | means slowing down — which is the point.
            */
            'regularity_samples' => (int) env('CATALOG_PACE_REGULARITY_SAMPLES', 15),
            'regularity_stddev_seconds' => (float) env('CATALOG_PACE_REGULARITY_STDDEV', 1.5),
        ],
    ],

];
