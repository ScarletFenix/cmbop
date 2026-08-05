<?php

if (! function_exists('safe_external_url')) {
    /**
     * Return a URL only when it is safe to put in an href.
     *
     * Publisher-supplied values (example_url, target_url…) reach the page as
     * plain strings. Blade escapes them, which stops markup injection but not a
     * `javascript:` or `data:` scheme — those still execute when clicked. Anything
     * that is not http(s) or root-relative is dropped.
     *
     * @param  string|null  $url
     * @param  string  $fallback  returned when the URL is missing or unsafe
     */
    function safe_external_url($url, string $fallback = '#'): string
    {
        $candidate = trim((string) $url);
        if ($candidate === '') {
            return $fallback;
        }

        // Strip control characters first: "java\0script:" and friends slip past a
        // naive scheme check but are still honoured by some browsers.
        $candidate = preg_replace('/[\x00-\x1F\x7F]/u', '', $candidate) ?? '';
        if ($candidate === '') {
            return $fallback;
        }

        if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            return $candidate;
        }

        if (preg_match('~^https?://~i', $candidate) !== 1) {
            return $fallback;
        }

        return $candidate;
    }
}
