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
        return $this->subject('Revised article ready — Order #'.$this->order->order_number)
            ->markdown('emails.publisher.content_revision_fulfilled');
    }
}
