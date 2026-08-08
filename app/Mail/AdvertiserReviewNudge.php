<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Fills the silence in the middle of the review window.
 *
 * The advertiser hears at submission and again 24h before auto-complete; between
 * those sat two days of nothing, which is where orders drift.
 */
class AdvertiserReviewNudge extends PlatformMailable
{
    public function __construct(
        public User $advertiser,
        public Order $order,
        public OrderItem $orderItem,
        public ?Site $site,
        public Carbon $autoCompletesAt,
    ) {
        parent::__construct();

        $this->notificationType = 'advertiser_review_nudge';
        $this->recipientUser = $advertiser;
        $this->dedupeKey = 'advertiser_review_nudge:'.$orderItem->id;
    }

    public function build()
    {
        $siteName = $this->site?->site_name ?: ($this->orderItem->site_name ?: 'your placement');

        return $this->subject('Your link is live — take a look at order #'.$this->order->order_number)
            ->markdown('emails.advertiser.review-nudge', [
                'firstName' => $this->firstName($this->advertiser),
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'siteName' => $siteName,
                'liveUrl' => $this->orderItem->live_url,
                'autoCompletesAt' => $this->autoCompletesAt,
                'ordersUrl' => $this->advertiserOrdersUrl((int) $this->order->id),
                'brand' => $this->brand(),
            ]);
    }
}
