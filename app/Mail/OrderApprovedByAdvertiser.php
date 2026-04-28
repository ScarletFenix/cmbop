<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderApprovedByAdvertiser extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $orderItem;
    public $site;
    public $basePrice;

    public function __construct(Order $order, OrderItem $orderItem, Site $site)
    {
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->basePrice = $orderItem->price - ($orderItem->additional_price ?? 0);
    }

    public function build()
    {
        return $this->subject('Order Approved by Advertiser - #' . $this->order->order_number)
                    ->markdown('emails.advertiser.order_approved_publisher');
    }
}