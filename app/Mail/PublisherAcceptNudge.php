<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;

/**
 * A paid order the publisher has not accepted yet. Nothing at all is moving on
 * it, which makes this the most urgent nudge in the system.
 */
class PublisherAcceptNudge extends PlatformMailable
{
    public function __construct(
        public User $publisher,
        public Order $order,
        public OrderItem $orderItem,
        public ?Site $site,
        public int $stage,
        public int $hoursWaiting,
    ) {
        parent::__construct();

        $this->notificationType = 'publisher_accept_nudge';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'publisher_accept_nudge:'.$orderItem->id.':'.$stage;
    }

    public function build()
    {
        $siteName = $this->site?->site_name ?: ($this->orderItem->site_name ?: 'your site');

        $subject = $this->stage >= 3
            ? 'Action needed: order #'.$this->order->order_number.' is still unaccepted'
            : 'A paid order is waiting on you — #'.$this->order->order_number;

        return $this->subject($subject)
            ->markdown('emails.publisher.accept-nudge', [
                'firstName' => $this->firstName($this->publisher),
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'siteName' => $siteName,
                'stage' => $this->stage,
                'hoursWaiting' => $this->hoursWaiting,
                'payout' => (float) $this->orderItem->publisherPayoutAmount(),
                'tasksUrl' => $this->publisherTasksUrl((int) $this->order->id),
                'brand' => $this->brand(),
            ]);
    }
}
