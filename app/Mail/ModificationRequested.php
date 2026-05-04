<?php
// app/Mail/ModificationRequested.php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ModificationRequested extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $reason;

    public function __construct(Order $order, $reason)
    {
        $this->order = $order;
        $this->reason = $reason;
    }

    public function build()
    {
        return $this->subject('Modification Requested for Order #' . $this->order->order_number)
                    ->markdown('emails.publisher.modification_requested');
    }
}