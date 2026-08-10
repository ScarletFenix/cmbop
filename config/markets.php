<?php

/**
 * Marketplace targeting: Europe + English-speaking regions + Latin America + Chinese + Gulf (Arabic).
 */
$allowedLanguageCodes = [
    // EU official languages (+ English)
    'bg', // Bulgarian
    'hr', // Croatian
    'cs', // Czech
    'da', // Danish
    'nl', // Dutch
    'en', // English
    'et', // Estonian
    'fi', // Finnish
    'fr', // French
    'de', // German
    'el', // Greek
    'hu', // Hungarian
    'ga', // Irish
    'it', // Italian
    'lv', // Latvian
    'lt', // Lithuanian
    'mt', // Maltese
    'pl', // Polish
    'pt', // Portuguese (EU + Latin America)
    'ro', // Romanian
    'sk', // Slovak
    'sl', // Slovenian
    'es', // Spanish (EU + Latin America)
    'sv', // Swedish

    // EU / European regional languages
    'ca', // Catalan
    'gl', // Galician
    'eu', // Basque
    'cy', // Welsh
    'gd', // Scottish Gaelic
    'lb', // Luxembourgish
    'rm', // Romansh
    'no', // Norwegian (EEA / Europe)

    // Chinese
    'zh',

    // Arabic (Gulf region)
    'ar',
];

$europeCountryCodes = [
    'al', 'at', 'ba', 'be', 'bg', 'ch', 'cy', 'cz', 'de', 'dk', 'ee', 'es', 'fi', 'fr',
    'gr', 'hr', 'hu', 'ie', 'is', 'it', 'li', 'lt', 'lu', 'lv', 'md', 'me', 'mk', 'mt', 'nl',
    'no', 'pl', 'pt', 'ro', 'rs', 'se', 'si', 'sk', 'ua', 'uk',
];

$englishRegionCountryCodes = [
    'us', // United States
    'ca', // Canada
    'uk', // United Kingdom (also Europe)
    'ie', // Ireland (also Europe)
    'au', // Australia
    'nz', // New Zealand
    'za', // South Africa
    'sg', // Singapore (English + Chinese)
];

$latinAmericaCountryCodes = [
    'ar', // Argentina
    'bo', // Bolivia
    'br', // Brazil
    'cl', // Chile
    'co', // Colombia
    'cr', // Costa Rica
    'cu', // Cuba
    'do', // Dominican Republic
    'ec', // Ecuador
    'sv', // El Salvador
    'gt', // Guatemala
    'hn', // Honduras
    'mx', // Mexico
    'ni', // Nicaragua
    'pa', // Panama
    'py', // Paraguay
    'pe', // Peru
    'pr', // Puerto Rico
    'uy', // Uruguay
    've', // Venezuela
];

$chineseCountryCodes = [
    'cn', // China
    'tw', // Taiwan
    'hk', // Hong Kong
    'mo', // Macau
    'sg', // Singapore
];

$gulfCountryCodes = [
    'ae', // United Arab Emirates
    'sa', // Saudi Arabia
    'qa', // Qatar
    'kw', // Kuwait
    'bh', // Bahrain
    'om', // Oman
];

$allowedCountryCodes = array_values(array_unique(array_merge(
    $europeCountryCodes,
    $englishRegionCountryCodes,
    $latinAmericaCountryCodes,
    $chineseCountryCodes,
    $gulfCountryCodes
)));

// Advertiser catalog country picker: fixed select helpers (buyer multi-select only).
$catalogCountryGroups = [
    'dach_plus' => ['de', 'at', 'ch', 'lu', 'li'],
    'nordics' => ['se', 'no', 'dk', 'fi', 'is'],
];

// Display order buckets (first match wins; a code never appears twice).
$bigEuropeOrder = ['de', 'fr', 'it', 'es', 'uk', 'nl', 'pl'];
$nordicsOrder = ['se', 'no', 'dk', 'fi', 'is'];
$smallEuropeOrder = array_values(array_diff($europeCountryCodes, $bigEuropeOrder, $nordicsOrder));
sort($smallEuropeOrder);
$bigEnglishOrder = ['us', 'ca', 'au'];
$otherEnglishOrder = ['nz', 'za', 'sg'];
$otherLanguageOrder = array_values(array_unique(array_merge(
    $latinAmericaCountryCodes,
    $chineseCountryCodes,
    $gulfCountryCodes
)));
sort($otherLanguageOrder);

$assignedOrderCodes = array_values(array_unique(array_merge(
    $bigEuropeOrder,
    $nordicsOrder,
    $smallEuropeOrder,
    $bigEnglishOrder,
    $otherEnglishOrder,
    $otherLanguageOrder
)));
$allOtherOrder = array_values(array_diff($allowedCountryCodes, $assignedOrderCodes));
sort($allOtherOrder);

return [

    'allowed_language_codes' => $allowedLanguageCodes,

    // Alias used by older migrations / scopes
    'european_language_codes' => $allowedLanguageCodes,

    'allowed_country_codes' => $allowedCountryCodes,

    'allowed_country_regions' => [
        'Europe',
        'North America',
        'Latin America',
        'East Asia',
        'Oceania',
        'Africa',
        'Middle East',
    ],

    'europe_country_codes' => $europeCountryCodes,
    'english_region_country_codes' => $englishRegionCountryCodes,
    'latin_america_country_codes' => $latinAmericaCountryCodes,
    'chinese_country_codes' => $chineseCountryCodes,
    'gulf_country_codes' => $gulfCountryCodes,

    /*
    |--------------------------------------------------------------------------
    | Catalog country picker groups (buyer shortcuts)
    |--------------------------------------------------------------------------
    |
    | Each site still belongs to exactly one country. These groups only help
    | advertisers multi-select related markets (OR filter).
    |
    */
    'catalog_country_groups' => $catalogCountryGroups,

    /*
    |--------------------------------------------------------------------------
    | Catalog country display order
    |--------------------------------------------------------------------------
    |
    | Popular + Recent pin above this in the UI. Within this list, codes follow
    | bucket priority 1→7. First bucket wins (uk is Big Europe only, not English).
    |
    */
    'catalog_country_order' => [
        'big_europe' => $bigEuropeOrder,
        'nordics' => $nordicsOrder,
        'small_europe' => $smallEuropeOrder,
        'big_english' => $bigEnglishOrder,
        'other_english' => $otherEnglishOrder,
        'other_language_markets' => $otherLanguageOrder,
        'all_other' => $allOtherOrder,
    ],

];
