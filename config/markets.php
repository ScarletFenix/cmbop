<?php

$allowedLanguageCodes = [
    'bg', // Bulgarian
    'be', // Belarusian
    'ca', // Catalan
    'hr', // Croatian
    'cs', // Czech
    'da', // Danish
    'nl', // Dutch
    'en', // English
    'et', // Estonian
    'fi', // Finnish
    'fr', // French
    'gl', // Galician
    'de', // German
    'el', // Greek
    'hu', // Hungarian
    'ga', // Irish
    'it', // Italian
    'lv', // Latvian
    'lt', // Lithuanian
    'lb', // Luxembourgish
    'mt', // Maltese
    'no', // Norwegian
    'pl', // Polish
    'pt', // Portuguese
    'ro', // Romanian
    'rm', // Romansh
    'ru', // Russian
    'gd', // Scottish Gaelic
    'sk', // Slovak
    'sl', // Slovenian
    'es', // Spanish
    'eu', // Basque
    'sv', // Swedish
    'tr', // Turkish
    'uk', // Ukrainian
    'cy', // Welsh
];

/**
 * Marketplace markets: Europe + major North American countries.
 * Languages stay Europe-focused (EN/ES/FR cover North America too).
 */
return [

    'allowed_language_codes' => $allowedLanguageCodes,

    // Alias for older migration / scopes
    'european_language_codes' => $allowedLanguageCodes,

    'allowed_country_regions' => [
        'Europe',
        'North America',
    ],

    'north_america_country_codes' => [
        'us', // United States
        'ca', // Canada
        'mx', // Mexico
    ],

];
