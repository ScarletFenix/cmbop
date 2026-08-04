<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Publisher domain visibility
    |--------------------------------------------------------------------------
    |
    | Catalog listings show a partially masked domain until the advertiser asks
    | for it. The point is not secrecy — anyone allowed to evaluate a site before
    | buying will end up knowing the domain — it is to stop a competitor
    | harvesting the whole inventory in an afternoon, and to leave a trail when a
    | publisher reports being approached directly.
    |
    | So the domain is metered and logged rather than hidden. Everything below
    | tunes the meter.
    |
    */
    'url_reveal' => [

        /*
        | Reveals per rolling day for an advertiser who has never funded a wallet
        | and never placed an order. Enough to evaluate a shortlist properly;
        | not enough to scrape a catalog.
        */
        'daily_allowance_new' => (int) env('CATALOG_REVEAL_DAILY_NEW', 25),

        /*
        | Once they have deposited or ordered, they are a customer rather than a
        | risk. Set to 0 for unlimited.
        */
        'daily_allowance_funded' => (int) env('CATALOG_REVEAL_DAILY_FUNDED', 0),

        /*
        | Adding a site to the cart reveals it too — you cannot check out against
        | a masked domain. That path is never blocked, because refusing to let
        | someone buy is worse than any scraping it could enable, but it is
        | recorded so the anomaly check below still sees it.
        */
        'count_cart_adds_against_allowance' => filter_var(
            env('CATALOG_REVEAL_METER_CART_ADDS', false),
            FILTER_VALIDATE_BOOL
        ),

        /*
        | Reveals by one advertiser within the window that should put a notice in
        | front of an admin. Tuned to sit well above a thorough shopping session
        | and well below a scrape.
        */
        'anomaly_threshold' => (int) env('CATALOG_REVEAL_ANOMALY_THRESHOLD', 60),
        'anomaly_window_minutes' => (int) env('CATALOG_REVEAL_ANOMALY_WINDOW', 60),
    ],

];
