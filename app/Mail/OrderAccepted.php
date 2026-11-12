<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Support\MailNotificationPresenter;

class OrderAccepted extends PlatformMailable
{
    public $order;

    public $orderItem;

    public $site;

    public $basePrice;

    public function __construct(Order $order, OrderItem $orderItem, Site $site)
    {
        parent::__construct();
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->basePrice = round(
            (float) $orderItem->price
            - (float) ($orderItem->additional_price ?? 0)
            - (float) ($orderItem->homepage_price ?? 0),
            2
        );
        $this->notificationType = 'order_accepted';
        $this->recipientUser = $order->user;
    }

    public function build()
    {
        $contentHref = MailNotificationPresenter::safeLiveUrl($this->orderItem->content_link);
        $extras = MailNotificationPresenter::articleLiveExtras($this->orderItem);
        $website = MailNotificationPresenter::orderSiteHeadline($this->order, $this->orderItem, $this->site);

        return $this->subject($this->siteNamedSubject('{site} accepted your order', $this->order, $this->orderItem, $this->site))
            ->markdown('emails.publisher.order_accepted')
            ->with([
                'order' => $this->order,
                'orderItem' => $this->orderItem,
                'site' => $this->site,
                'detailRows' => MailNotificationPresenter::filledMailRows([
                    ['label' => 'Website', 'value' => $website],
                    ['label' => 'Order', 'value' => $this->order->order_number ? '#'.$this->order->order_number : ''],
                    [
                        'label' => 'Content',
                        'value' => $contentHref ? MailNotificationPresenter::displayUrl($contentHref) : '',
                        'url' => $contentHref,
                    ],
                    ['label' => 'Homepage placement', 'value' => $extras['homepage_placement']],
                    ['label' => 'Social promotion', 'value' => $extras['social_promotion']],
                ]),
                'ctaUrl' => $this->advertiserOrdersUrl((int) $this->order->id),
            ]);
    }
}
