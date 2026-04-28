<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LiveUrlSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $orderItem;
    public $site;
    public $liveUrl;

    public function __construct(Order $order, OrderItem $orderItem, Site $site, $liveUrl)
    {
        $this->order = $order;
        $this->orderItem = $orderItem;
        $this->site = $site;
        $this->liveUrl = $liveUrl;
    }

    public function build()
    {
        return $this->subject('Live URL Submitted - #' . $this->order->order_number)
                    ->markdown('emails.publisher.live_url_submitted');
    }
}