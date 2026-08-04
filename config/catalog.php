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
        | Once they have deposited or ordered they are a customer rather than a
        | risk, so this is generous — far beyond any real shopping session. It is
        | deliberately not unlimited: a competitor will happily pay for one small
        | deposit if that buys them the whole inventory list. Set to 0 to remove
        | the ceiling entirely, understanding what that gives away.
        */
        'daily_allowance_funded' => (int) env('CATALOG_REVEAL_DAILY_FUNDED', 200),

        /*
        | Adding a site to the cart reveals it too, because you cannot check out
        | against a masked domain — which makes a scripted basket a way to
        | download the catalog without touching a single reveal.
        |
        | So baskets are free up to this many distinct new sites a day, which is
        | far more than anyone buys at once. Past it, a cart add costs an
        | allowance like any other disclosure. A real buyer never notices; a
        | script hits a wall.
        */
        'cart_add_free_per_day' => (int) env('CATALOG_CART_FREE_REVEALS', 15),

        /*
        | Hard stop. Notifying an admin is useful but passive — the inventory is
        | gone long before anyone reads the bell — so past this many reveals
        | inside the anomaly window the account is refused until it slows down,
        | whatever allowance it holds.
        */
        'burst_ceiling' => (int) env('CATALOG_REVEAL_BURST_CEILING', 120),

        /*
        | Reveals by one advertiser within the window that should put a notice in
        | front of an admin. Tuned to sit well above a thorough shopping session
        | and well below a scrape.
        */
        'anomaly_threshold' => (int) env('CATALOG_REVEAL_ANOMALY_THRESHOLD', 60),
        'anomaly_window_minutes' => (int) env('CATALOG_REVEAL_ANOMALY_WINDOW', 60),
    ],

];
