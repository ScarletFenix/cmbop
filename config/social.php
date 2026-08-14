<?php

return [

    /*
    | Official public profiles. Empty SOCIAL_* env values fall back to these
    | so the footer and Organization sameAs stay populated.
    */
    'profiles' => [
        'linkedin' => [
            'label' => 'LinkedIn',
            'url' => env('SOCIAL_LINKEDIN_URL') ?: 'https://www.linkedin.com/company/seolinkbuildings',
            'icon' => 'fab fa-linkedin',
        ],
        'facebook' => [
            'label' => 'Facebook',
            'url' => env('SOCIAL_FACEBOOK_URL') ?: 'https://www.facebook.com/seolinkbuildings/',
            'icon' => 'fab fa-facebook',
        ],
        'instagram' => [
            'label' => 'Instagram',
            'url' => env('SOCIAL_INSTAGRAM_URL') ?: 'https://www.instagram.com/seolinkbuildings',
            'icon' => 'fab fa-instagram',
        ],
        'x' => [
            'label' => 'X',
            'url' => env('SOCIAL_X_URL') ?: env('SOCIAL_TWITTER_URL') ?: 'https://x.com/seolinbuildings',
            'icon' => 'fab fa-x-twitter',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'url' => env('SOCIAL_YOUTUBE_URL') ?: 'https://www.youtube.com/@seolinkbuildingss',
            'icon' => 'fab fa-youtube',
        ],
    ],

];
