<?php

namespace App\Support;

trait NormalizesHttpUrls
{
    /**
     * Ensure URLs validate even when publishers omit the scheme.
     */
    protected function normalizeHttpUrl(mixed $url): string
    {
        if (is_array($url)) {
            $flat = [];
            array_walk_recursive($url, function ($item) use (&$flat) {
                if (is_scalar($item)) {
                    $flat[] = $item;
                }
            });
            $url = $flat[0] ?? '';
        }

        if (! is_scalar($url) && $url !== null) {
            return '';
        }

        $url = trim((string) $url);
        if ($url === '') {
            return $url;
        }

        if (! preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
