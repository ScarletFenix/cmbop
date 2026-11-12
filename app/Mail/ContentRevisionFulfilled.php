<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class ContentRevisionFulfilled extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public OrderItem $orderItem,
        public Site $site,
    ) {
        parent::__construct();
        $this->notificationType = 'content_revision_fulfilled';
    }

    public function build()
    {
        return $this->subject($this->siteNamedSubject('Revised article ready on {site}', $this->order, $this->orderItem, $this->site))
            ->markdown('emails.publisher.content_revision_fulfilled');
    }
}
