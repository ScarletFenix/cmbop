<?php

// app/Mail/ModificationRequested.php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;

class ModificationRequested extends PlatformMailable
{
    public $order;

    public $reason;

    public function __construct(Order $order, $reason)
    {
        parent::__construct();
        $this->order = $order;
        $this->reason = $reason;
    }

    public function build()
    {
        return $this->subject('Modification Requested for Order #'.$this->order->order_number)
            ->markdown('emails.publisher.modification_requested')
            ->with([
                'order' => $this->order,
                'reason' => $this->reason,
                'autoApproveHours' => OrderItem::autoApproveHours(),
            ]);
    }
}
