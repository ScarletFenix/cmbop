<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class ContentRevisionRequested extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public OrderItem $orderItem,
        public Site $site,
        public string $reason,
    ) {
        parent::__construct();
        $this->notificationType = 'content_revision_requested';
    }

    public function build()
    {
        return $this->subject($this->siteNamedSubject('Correction requested on {site}', $this->order, $this->orderItem, $this->site))
            ->markdown('emails.advertiser.content_revision_requested');
    }
}
