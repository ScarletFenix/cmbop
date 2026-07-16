<?php

/**
 * Native content upload settings (replaces Google Docs URL submission).
 * Admin overrides are merged via content_moderation_settings key "upload_config".
 */
return [

    'enabled' => env('CONTENT_UPLOAD_ENABLED', true),

    /** Preferred / primary format */
    'preferred_extension' => 'docx',

    'allowed_extensions' => ['docx', 'doc', 'pdf'],

    'allowed_mimes' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
        'application/msword', // doc
        'application/pdf',
        'application/octet-stream', // some browsers
    ],

    /** Max upload size in kilobytes (5120 = 5 MB) */
    'max_kilobytes' => (int) env('CONTENT_UPLOAD_MAX_KB', 5120),

    'disk' => env('CONTENT_UPLOAD_DISK', 'local'),

    'directory' => 'content-uploads',

    /** Retention before automatic purge */
    'retention_months' => 6,

    'scheduling' => [
        'enabled' => env('CONTENT_SCHEDULING_ENABLED', true),
        /** Max months into the future an advertiser may schedule */
        'max_months' => 3,
        'default_timezone' => 'UTC',
        'reminder_hours_before' => 24,
    ],

    'anchor_text' => [
        'max_length' => 120,
        'min_length' => 1,
    ],

    'feature_image' => [
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],

    'help' => [
        'preferred_format' => 'For the best experience, upload your article as a Microsoft Word (.docx) document.',
        'anchor_text' => 'Enter the exact anchor text that should appear in the article.',
        'target_url' => 'Enter the website URL that the anchor text should link to.',
        'feature_image' => 'If you would like the publisher to use a featured image, provide a royalty-free image URL from platforms such as Pixabay, Pexels, Unsplash, or similar sources.',
        'compliance_reject' => 'This article contains content that violates our publishing guidelines.' . "\n\n"
            . 'Please upload a revised document before continuing.',
    ],
];
