<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;

class OrderApprovedByAdvertiser extends PlatformMailable
{
    public $order;

    public $orderItem;

    public $site;

    public $basePrice;

    public $payoutAmount;

    public function __construct(Order $order, OrderItem $orderItem, Site $site)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        // Show publisher earnings (excludes the 15% platform fee)
        $this->basePrice = $orderItem->publisherBasePrice();
        $this->payoutAmount = $orderItem->publisherPayoutAmount();
        $this->notificationType = 'order_completed';
    }

    public function build()
    {
        if ($this->orderItem instanceof OrderItem) {
            $this->orderItem = $this->orderItem->fresh() ?? $this->orderItem;
        }

        return $this->subject($this->siteNamedSubject(
            'Article approved on {site}',
            $this->order,
            $this->orderItem,
            $this->site,
        ))
            ->markdown('emails.advertiser.order_approved_publisher');
    }
}
