<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

class BulkSiteRequestSubmitted extends PlatformMailable
{
    public BulkSiteRequest $bulkRequest;

    public function __construct(BulkSiteRequest $bulkRequest, ?User $recipient = null)
    {
        parent::__construct();
        $this->bulkRequest = $bulkRequest;
        $this->notificationType = 'bulk_site_request_submitted';
        $this->skipUserPreference = true;
        $this->recipientUser = $recipient;
        $this->dedupeKey = 'bulk-request-'.$bulkRequest->id.':staff:'.($recipient?->id ?? 'fallback');
    }

    public function build()
    {
        $publisher = $this->bulkRequest->publisher;

        return $this->subject('Bulk site request from '.($publisher->name ?? 'publisher'))
            ->markdown('emails.bulk-site-request-submitted')
            ->with([
                'bulkRequest' => $this->bulkRequest,
                'publisherName' => $publisher->name ?? 'Unknown',
                'publisherEmail' => $publisher->email ?? 'Unknown',
                'adminUrl' => staff_route_for($this->recipientUser, 'bulk-site-requests.show', $this->bulkRequest),
            ]);
    }
}
