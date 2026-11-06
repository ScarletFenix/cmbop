<?php

namespace App\Mail;

use App\Models\Order;

class ManagedOrderConfirmedMail extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public string $bodyCopy,
    ) {
        parent::__construct();
    }

    public function build()
    {
        return $this->subject('Order confirmed · Managed by SLB · #'.$this->order->order_number)
            ->markdown('emails.advertiser.managed-order-confirmed')
            ->with([
                'order' => $this->order,
                'bodyCopy' => $this->bodyCopy,
            ]);
    }
}
