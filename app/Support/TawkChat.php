<?php

namespace App\Support;

/**
 * Public Tawk.to visitor chat. Order chat (advertiser ↔ publisher) is separate.
 */
class TawkChat
{
    public static function enabled(): bool
    {
        return self::embedSrc() !== null;
    }

    public static function embedSrc(): ?string
    {
        $property = trim((string) config('services.tawk.property_id', ''));
        $widget = trim((string) config('services.tawk.widget_id', ''));

        if ($property === '' || $widget === '') {
            return null;
        }

        if (! preg_match('/^[a-f0-9]{24}$/i', $property)) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $widget)) {
            return null;
        }

        return 'https://embed.tawk.to/'.$property.'/'.$widget;
    }
}
