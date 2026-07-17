<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Branded email-verification notification used on signup / resend.
 * Kept synchronous (not queued) so it does not depend on queue workers.
 */
class VerifyEmail extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = static::signedUrlFor($notifiable);
        $appName = config('app.name', 'SEOLinkBuildings');
        $name = trim((string) ($notifiable->name ?? 'there'));
        $firstName = explode(' ', $name)[0] ?: 'there';
        $minutes = (int) Config::get('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject("Verify your {$appName} email")
            ->greeting("Hi {$firstName},")
            ->line('Thanks for creating your account. Please verify your email address to activate login and start using the marketplace.')
            ->action('Click to verify', $verificationUrl)
            ->line("This verification link expires in {$minutes} minutes.")
            ->line('If you did not create an account, no further action is required.')
            ->salutation('Thanks, '.PHP_EOL.$appName.' Team');
    }

    /**
     * Absolute signed verification URL for a user (welcome email + notification).
     */
    public static function signedUrlFor($notifiable): string
    {
        // Ensure links use the configured public site URL (not localhost behind reverse proxies).
        $root = rtrim((string) config('app.url'), '/');
        if ($root !== '') {
            URL::forceRootUrl($root);
        }

        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }

        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }

    protected function verificationUrl($notifiable): string
    {
        return static::signedUrlFor($notifiable);
    }
}
