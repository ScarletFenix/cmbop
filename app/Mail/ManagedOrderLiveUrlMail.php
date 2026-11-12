<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\MailNotificationPresenter;
use App\Support\TransactionalMailTheme;

class ManagedOrderLiveUrlMail extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public OrderItem $orderItem,
        public string $bodyCopy,
    ) {
        parent::__construct();
        $this->recipientUser = $order->user;
        $this->notificationType = 'live_url_submitted';
    }

    public function build()
    {
        $this->order->loadMissing(['user', 'items.site']);
        $safeUrl = MailNotificationPresenter::safeLiveUrl($this->orderItem->live_url);
        $ctaUrl = $this->advertiserOrdersUrl((int) $this->order->id);
        $articleLive = TransactionalMailTheme::articleLivePayload([
            'order' => $this->order,
            'orderItem' => $this->orderItem,
            'liveUrl' => $safeUrl,
            'ctaUrl' => $ctaUrl,
        ], true);

        return $this->subject('Your article is live · #'.$this->order->order_number)
            ->markdown('emails.advertiser.managed-order-live-url')
            ->with([
                'firstName' => $this->firstName($this->order->user),
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'bodyCopy' => $this->bodyCopy,
                'articleLive' => $articleLive,
                'reviewReady' => true,
                'placements' => [[
                    'site_label' => MailNotificationPresenter::siteLabel($this->orderItem),
                    'site_name' => MailNotificationPresenter::siteName($this->orderItem),
                    'domain' => MailNotificationPresenter::siteDomain($this->orderItem),
                    'live_url' => $safeUrl,
                    'pending' => $safeUrl === null,
                ]],
                'ctaUrl' => $ctaUrl,
                'supportLine' => MailNotificationPresenter::supportLine('order'),
                'brand' => $this->brand(),
            ]);
    }
}
