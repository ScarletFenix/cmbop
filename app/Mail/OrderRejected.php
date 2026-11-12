<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Support\MailNotificationPresenter;

class OrderRejected extends PlatformMailable
{
    public $order;

    public $orderItem;

    public $site;

    public $reason;

    public function __construct(Order $order, OrderItem $orderItem, Site $site, $reason)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->reason = $reason;
        $this->notificationType = 'order_rejected';
        $this->recipientUser = $order->user;
    }

    public function build()
    {
        $website = MailNotificationPresenter::orderSiteHeadline($this->order, $this->orderItem, $this->site);

        return $this->subject($this->siteNamedSubject('{site} did not accept your order', $this->order, $this->orderItem, $this->site))
            ->markdown('emails.publisher.order_rejected')
            ->with([
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'site' => $this->site,
                'reason' => $this->reason,
                'detailRows' => MailNotificationPresenter::filledMailRows([
                    ['label' => 'Website', 'value' => $website],
                    ['label' => 'Order', 'value' => $this->order->order_number ? '#'.$this->order->order_number : ''],
                ]),
                'ctaUrl' => $this->customerFacingRoute('advertiser.catalog'),
            ]);
    }
}
