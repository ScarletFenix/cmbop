<?php

// app/Mail/ModificationRequested.php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\MailNotificationPresenter;

class ModificationRequested extends PlatformMailable
{
    public $order;

    public $reason;

    public function __construct(Order $order, $reason)
    {
        parent::__construct();
        $this->order = $order;
        $this->reason = $reason;
        $this->notificationType = 'modification_requested';
    }

    public function build()
    {
        $this->order->loadMissing('items.site');
        $item = $this->order->items->first();

        return $this->subject($this->siteNamedSubject('Correction requested on {site}', $this->order, $item instanceof OrderItem ? $item : null))
            ->markdown('emails.publisher.modification_requested')
            ->with([
                'order' => $this->order,
                'reason' => $this->reason,
                'autoApproveHours' => OrderItem::autoApproveHours(),
                'detailRows' => MailNotificationPresenter::filledMailRows([
                    ['label' => 'Website', 'value' => MailNotificationPresenter::orderSiteHeadline($this->order, $item instanceof OrderItem ? $item : null)],
                    ['label' => 'Order', 'value' => $this->order->order_number ? '#'.$this->order->order_number : ''],
                    ['label' => 'Status', 'value' => 'Processing (awaiting updates)'],
                ]),
                'ctaUrl' => $this->publisherTasksUrl((int) $this->order->id),
            ]);
    }
}
