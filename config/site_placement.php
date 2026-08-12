<?php

/**
 * Publisher listing add-ons: homepage placement durations and social boost channels.
 * Distinct from site_promotions.feature (catalog “Feature this site”).
 */
return [

    /*
    | Allowed homepage placement durations (days) advertisers may buy.
    */
    'homepage_days' => [1, 7, 30],

    /*
    | Social channels publishers may offer (always €0 for advertisers).
    */
    'social_channels' => ['facebook', 'instagram', 'x'],

    /*
    | Soft host allowlist when publishers submit social post URLs on delivery.
    */
    'social_hosts' => [
        'facebook' => [
            'facebook.com',
            'www.facebook.com',
            'm.facebook.com',
            'fb.com',
            'www.fb.com',
            'fb.watch',
        ],
        'instagram' => [
            'instagram.com',
            'www.instagram.com',
        ],
        'x' => [
            'x.com',
            'www.x.com',
            'twitter.com',
            'www.twitter.com',
            'mobile.twitter.com',
        ],
    ],
];
