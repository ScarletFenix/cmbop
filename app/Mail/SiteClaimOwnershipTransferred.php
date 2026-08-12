<?php

namespace App\Mail;

use App\Models\SiteClaim;
use App\Models\User;

class SiteClaimOwnershipTransferred extends PlatformMailable
{
    public SiteClaim $claim;

    public User $previousPublisher;

    public function __construct(SiteClaim $claim, User $previousPublisher)
    {
        parent::__construct();
        $this->claim = $claim->loadMissing(['site', 'claimer']);
        $this->previousPublisher = $previousPublisher;
        $this->notificationType = 'site_claim_ownership_transferred';
        $this->recipientUser = $previousPublisher;
        $this->dedupeKey = 'site-claim-ownership-'.$claim->id;
    }

    public function build()
    {
        $siteName = $this->claim->site?->site_name ?: $this->claim->website_name;

        return $this->subject('Ownership transferred: '.$siteName)
            ->markdown('emails.site-claim-ownership-transferred')
            ->with([
                'claim' => $this->claim,
                'siteName' => $siteName,
                'previousPublisher' => $this->previousPublisher,
            ]);
    }
}
