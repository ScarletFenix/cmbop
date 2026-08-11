<?php

namespace App\Support;

trait NormalizesHttpUrls
{
    /**
     * Ensure URLs validate even when publishers omit the scheme.
     */
    protected function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        if (! preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'https://'.$url;
        }

        return $url;
    }
}
