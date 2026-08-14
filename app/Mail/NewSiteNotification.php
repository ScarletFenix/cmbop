<?php

// app/Mail/NewSiteNotification.php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;

class NewSiteNotification extends PlatformMailable
{
    public $site;

    public $action;

    public function __construct(Site $site, $action = 'create', ?User $recipient = null)
    {
        parent::__construct();
        $this->site = $site;
        $this->action = $action;
        $this->recipientUser = $recipient;
    }

    public function build()
    {
        $subject = $this->action === 'create'
            ? 'New Site Submitted for Review'
            : 'Site Updated - Requires Review';

        $this->site->loadMissing('publisher');
        $publisherId = (int) ($this->site->publisher_id ?? 0);

        $adminUrl = staff_route_for($this->recipientUser, 'sites.index', array_filter([
            'needs_review' => 1,
            'publisher' => $publisherId > 0 ? $publisherId : null,
            'site' => $this->site->id,
        ]));

        return $this->subject($subject)
            ->markdown('emails.new-site-notification')
            ->with([
                'siteName' => $this->site->site_name,
                'siteUrl' => $this->site->site_url,
                'publisherName' => $this->site->publisher->name ?? 'Unknown',
                'publisherEmail' => $this->site->publisher->email ?? 'Unknown',
                'action' => $this->action,
                'adminUrl' => $adminUrl,
            ]);
    }
}
