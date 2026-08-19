<?php

use App\Support\SiteDescriptionRules;

if (! function_exists('site_description_excerpt')) {
    /**
     * Plain-text catalog excerpt for a site description (strips HTML first).
     */
    function site_description_excerpt(?string $html, ?int $limit = null): string
    {
        return SiteDescriptionRules::excerpt($html, $limit);
    }
}

if (! function_exists('catalog_description_translate_url')) {
    /**
     * Google Translate URL for a catalog description excerpt (v1, no API).
     */
    function catalog_description_translate_url(?string $html, string $target = 'en'): ?string
    {
        return SiteDescriptionRules::googleTranslateUrl($html, $target);
    }
}
