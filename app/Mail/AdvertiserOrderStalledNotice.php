<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Tells the advertiser their publisher is late before they have to ask.
 *
 * The complaint about a late order is rarely the delay itself — it is not being
 * told. This says we noticed, we are chasing, and here is what happens next.
 */
class AdvertiserOrderStalledNotice extends PlatformMailable
{
    public function __construct(
        public User $advertiser,
        public Order $order,
        public OrderItem $orderItem,
        public ?Site $site,
        public Carbon $dueAt,
        public int $hoursOverdue,
    ) {
        parent::__construct();

        $this->notificationType = 'advertiser_order_stalled';
        $this->recipientUser = $advertiser;
        $this->dedupeKey = 'advertiser_order_stalled:'.$orderItem->id;
    }

    public function build()
    {
        $siteName = $this->site?->site_name ?: ($this->orderItem->site_name ?: 'your placement');

        return $this->subject('We are chasing your order #'.$this->order->order_number)
            ->markdown('emails.advertiser.order-stalled', [
                'firstName' => $this->firstName($this->advertiser),
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'siteName' => $siteName,
                'dueAt' => $this->dueAt,
                'daysOverdue' => max(1, (int) round($this->hoursOverdue / 24)),
                'ordersUrl' => route('advertiser.orders'),
                'brand' => $this->brand(),
            ]);
    }
}
