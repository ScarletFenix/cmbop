<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;

class ManagedOrderLiveUrlMail extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public OrderItem $orderItem,
        public string $bodyCopy,
    ) {
        parent::__construct();
    }

    public function build()
    {
        return $this->subject('Your article is live · #'.$this->order->order_number)
            ->markdown('emails.advertiser.managed-order-live-url')
            ->with([
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'bodyCopy' => $this->bodyCopy,
                'liveUrl' => $this->orderItem->live_url,
            ]);
    }
}
