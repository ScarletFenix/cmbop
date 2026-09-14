<?php

namespace App\Support;

/**
 * First-party visitor support chat (public + advertiser/publisher).
 * Order chat (advertiser ↔ publisher) is a separate product.
 */
class VisitorSupportChat
{
    public static function enabled(): bool
    {
        return (bool) config('services.support_chat.enabled', false);
    }

    public static function companyName(): string
    {
        $name = trim((string) config('app.name', ''));

        return $name !== '' ? $name : 'SEOLinkBuildings';
    }

    public static function welcomeMessage(): string
    {
        return 'Hi! 👋 How can we help you today?';
    }
}
