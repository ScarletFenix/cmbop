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
        $this->user->loadMissing('roles');

        $needsVerification = ! $this->user->hasVerifiedEmail();
        $workspace = $this->workspaceRole();
        $base = rtrim(app_public_url(), '/');

        $catalogUrl = $base.'/advertiser/catalog';
        $dashboardUrl = $workspace === 'publisher'
            ? $base.route('publisher.dashboard', absolute: false)
            : $base.'/advertiser/dashboard';
        $websitesUrl = $base.route('publisher.websites', absolute: false);

        if ($needsVerification) {
            $ctaUrl = VerifyEmail::signedUrlFor($this->user);
            $ctaLabel = 'Click to verify';
        } elseif ($workspace === 'publisher') {
            $ctaUrl = $websitesUrl;
            $ctaLabel = 'Add your first website';
        } else {
            $ctaUrl = $catalogUrl;
            $ctaLabel = 'Browse Websites';
        }

        return $this->subject('Welcome to '.config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.welcome')
            ->with([
                'user' => $this->user,
                'firstName' => $this->firstName($this->user),
                'workspace' => $workspace,
                'catalogUrl' => $catalogUrl,
                'dashboardUrl' => $dashboardUrl,
                'websitesUrl' => $websitesUrl,
                'ctaUrl' => $ctaUrl,
                'ctaLabel' => $ctaLabel,
                'needsVerification' => $needsVerification,
                'loginUrl' => $base.'/login',
                'brand' => $this->brand(),
            ]);
    }

    /**
     * Starting workspace for welcome copy/CTA.
     *
     * Prefers active_role_id (registration starting workspace), then falls back
     * to the first attached role. Unknown roles default to advertiser copy.
     */
    protected function workspaceRole(): string
    {
        $active = strtolower((string) ($this->user->activeRole() ?? ''));

        if (in_array($active, ['publisher', 'advertiser'], true)) {
            return $active;
        }

        $names = $this->user->roles->pluck('name')->map(fn ($n) => strtolower((string) $n));

        if ($names->contains('publisher') && ! $names->contains('advertiser')) {
            return 'publisher';
        }

        return 'advertiser';
    }
}
