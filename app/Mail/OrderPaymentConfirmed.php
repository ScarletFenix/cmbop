<?php

// app/Mail/OrderPaymentConfirmed.php

namespace App\Mail;

use App\Models\Order;
use App\Support\MailNotificationPresenter;
use Illuminate\Support\Facades\Log;

class OrderPaymentConfirmed extends PlatformMailable
{
    public $order;

    public $user;

    public $totalAmount;

    public $orderItems;

    public function __construct(Order $order)
    {
        parent::__construct();
        $this->order = $order;
        $this->user = $order->user;
        $this->totalAmount = $order->total_amount;
        $this->orderItems = $order->items;

        $this->notificationType = 'order_payment_confirmed';
        $this->recipientUser = $this->user;

        Log::info('OrderPaymentConfirmed mail initialized', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_email' => $this->user->email ?? 'No email',
        ]);
    }

    public function build()
    {
        $subject = 'Payment Confirmed for Order #'.$this->order->order_number;

        return $this->subject($subject)
            ->markdown('emails.order-payment-confirmed')
            ->with([
                'order' => $this->order,
                'user' => $this->user,
                'totalAmount' => $this->totalAmount,
                'orderItems' => $this->orderItems,
                'orderDate' => MailNotificationPresenter::formatDateTime($this->order->created_at),
                'paidDate' => MailNotificationPresenter::formatDateTime($this->order->paid_at ?: now()),
                'detailRows' => MailNotificationPresenter::orderPlacedDetailRows($this->order, $this->totalAmount),
                'itemCards' => MailNotificationPresenter::orderPlacedItemCards($this->orderItems ?? []),
            ]);
    }
}
