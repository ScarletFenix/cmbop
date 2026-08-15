<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Temporary signed URLs for campaign marketing opt-out.
 *
 * HMAC is signed against the path only (absolute: false) so www/apex or
 * APP_URL host drift cannot invalidate the link; the public origin is prefixed
 * the same way as email verification CTAs.
 */
class EmailUnsubscribeLink
{
    public static function expireDays(): int
    {
        return max(1, (int) config('email_notifications.unsubscribe_expire_days', 30));
    }

    public static function expiresAt(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addDays(self::expireDays());
    }

    public static function previewUrl(): string
    {
        return rtrim(app_public_url(), '/').'/email/unsubscribe/preview-id';
    }

    public static function url(User|int $user): string
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        if ($userId === EmailCatalog::PREVIEW_ID
            || ($user instanceof User && EmailCatalog::isPreviewUser($user))) {
            return self::previewUrl();
        }

        $relative = URL::temporarySignedRoute(
            'email.unsubscribe',
            self::expiresAt(),
            ['user' => $userId],
            absolute: false
        );

        return rtrim(app_public_url(), '/').$relative;
    }
}
