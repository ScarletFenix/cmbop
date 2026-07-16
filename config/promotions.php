<?php

/**
 * Admin-managed announcements and advertisement banner sizes/placements.
 */
return [

    'announcement_types' => [
        'discount' => ['label' => 'Discount', 'icon' => 'fa-percent'],
        'black_friday' => ['label' => 'Black Friday', 'icon' => 'fa-bolt'],
        'offer' => ['label' => 'Special Offer', 'icon' => 'fa-tags'],
        'change' => ['label' => 'Platform Change', 'icon' => 'fa-bullhorn'],
        'general' => ['label' => 'General Update', 'icon' => 'fa-info-circle'],
        'maintenance' => ['label' => 'Maintenance', 'icon' => 'fa-tools'],
    ],

    'announcement_styles' => [
        'info' => 'Info (teal)',
        'success' => 'Success (green)',
        'warning' => 'Warning (amber)',
        'danger' => 'Urgent (red)',
        'promo' => 'Promo (gradient)',
    ],

    'audiences' => [
        'all' => 'Everyone',
        'public' => 'Public website only',
        'advertiser' => 'Advertisers only',
        'publisher' => 'Publishers only',
    ],

    /*
    | Standard IAB-friendly sizes that fit common website slots.
    */
    'banner_sizes' => [
        'leaderboard' => ['label' => 'Leaderboard', 'width' => 728, 'height' => 90, 'hint' => 'Header / top of page'],
        'billboard' => ['label' => 'Billboard', 'width' => 970, 'height' => 250, 'hint' => 'Wide hero / content top'],
        'medium_rectangle' => ['label' => 'Medium Rectangle', 'width' => 300, 'height' => 250, 'hint' => 'Sidebar / in-content'],
        'large_rectangle' => ['label' => 'Large Rectangle', 'width' => 336, 'height' => 280, 'hint' => 'Sidebar / in-content'],
        'half_page' => ['label' => 'Half Page', 'width' => 300, 'height' => 600, 'hint' => 'Tall sidebar'],
        'wide_skyscraper' => ['label' => 'Wide Skyscraper', 'width' => 160, 'height' => 600, 'hint' => 'Narrow sidebar'],
        'mobile_leaderboard' => ['label' => 'Mobile Leaderboard', 'width' => 320, 'height' => 50, 'hint' => 'Mobile header'],
        'mobile_banner' => ['label' => 'Mobile Banner', 'width' => 320, 'height' => 100, 'hint' => 'Mobile content'],
        'square' => ['label' => 'Square', 'width' => 250, 'height' => 250, 'hint' => 'Compact slot'],
        'small_square' => ['label' => 'Small Square', 'width' => 200, 'height' => 200, 'hint' => 'Small ad unit'],
        'custom' => ['label' => 'Custom size', 'width' => 0, 'height' => 0, 'hint' => 'Set width & height manually'],
    ],

    'banner_placements' => [
        'header' => 'Header (below nav)',
        'content_top' => 'Content top',
        'content_bottom' => 'Content bottom',
        'sidebar' => 'Sidebar',
        'footer' => 'Above footer',
        'marketplace' => 'Marketplace / catalog',
        'dashboard' => 'User dashboard',
    ],
];
