<?php
// app/Mail/SiteOwnerOrderNotification.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class SiteOwnerOrderNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $site;
    public $orders;
    public $publisher;

    public function __construct($site, $orders)
    {
        $this->site = $site;
        $this->orders = $orders;
        // Get the publisher using publisher_id
        $this->publisher = User::find($site->publisher_id);
    }

    public function build()
    {
        $totalAmount = 0;
        $orderNumbers = [];
        
        foreach ($this->orders as $order) {
            $totalAmount += $order->total_amount;
            $orderNumbers[] = $order->order_number;
        }
        
        // Get publisher name safely
        $publisherName = 'Publisher';
        if ($this->publisher) {
            $publisherName = $this->publisher->name;
        }
        
        return $this->subject('New Order for Your Site: ' . $this->site->site_name)
                    ->markdown('emails.site-owner-order-notification')
                    ->with([
                        'site' => $this->site,
                        'orders' => $this->orders,
                        'totalAmount' => $totalAmount,
                        'orderNumbers' => implode(', ', $orderNumbers),
                        'orderCount' => count($this->orders),
                        'publisherName' => $publisherName,
                        'publisher' => $this->publisher
                    ]);
    }
}