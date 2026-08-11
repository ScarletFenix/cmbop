<?php

namespace App\Mail;

use App\Models\User;
use App\Notifications\VerifyEmail;

class WelcomeEmail extends PlatformMailable
{
    public function __construct(public User $user)
    {
        parent::__construct();
        $this->notificationType = 'welcome';
        $this->recipientUser = $user;
    }

    public function build()
    {
        $needsVerification = ! $this->user->hasVerifiedEmail();
        $catalogUrl = $this->publicRoute('advertiser.catalog');
        $dashboardUrl = $this->publicRoute('advertiser.dashboard');

        // Must be the signed /email/verify/{id}/{hash} URL — NOT /email/verify
        // (that notice route requires auth and never verifies the account).
        $verifyUrl = $needsVerification
            ? VerifyEmail::signedUrlFor($this->user)
            : $catalogUrl;

        return $this->subject('Welcome to '.config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.welcome')
            ->with([
                'user' => $this->user,
                'firstName' => $this->firstName($this->user),
                'catalogUrl' => $catalogUrl,
                'dashboardUrl' => $dashboardUrl,
                'ctaUrl' => $verifyUrl,
                'ctaLabel' => $needsVerification ? 'Click to verify' : 'Browse Websites',
                'needsVerification' => $needsVerification,
                'loginUrl' => $this->publicRoute('login'),
                'brand' => $this->brand(),
            ]);
    }
}
