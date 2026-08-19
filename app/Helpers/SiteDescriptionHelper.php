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
