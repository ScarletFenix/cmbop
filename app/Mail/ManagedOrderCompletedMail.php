<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;

class ManagedOrderCompletedMail extends PlatformMailable
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
        return $this->subject('Completed · Managed by SLB · #'.$this->order->order_number)
            ->markdown('emails.advertiser.managed-order-completed')
            ->with([
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'bodyCopy' => $this->bodyCopy,
                'liveUrl' => $this->orderItem->live_url,
            ]);
    }
}
