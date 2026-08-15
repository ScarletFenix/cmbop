<?php

namespace App\Mail;

use App\Models\Invoice;

class PaymentSuccessfulInvoiceMail extends PlatformMailable
{
    public function __construct(
        public Invoice $invoice,
        public ?Invoice $receipt = null,
    ) {
        parent::__construct();
        $this->notificationType = 'payment_successful_invoice';
        $this->recipientUser = $invoice->user;
        $this->dedupeKey = 'payment_successful_invoice:'.$invoice->id;
    }

    public function build()
    {
        $order = $this->invoice->order;
        $user = $this->invoice->user;
        $symbol = config('billing.currency_symbol', '€');

        $mail = $this->subject('Payment Successful – Invoice Attached')
            ->markdown('emails.billing.payment-successful', [
                'invoice' => $this->invoice,
                'receipt' => $this->receipt,
                'order' => $order,
                'user' => $user,
                'symbol' => $symbol,
                'viewOrderUrl' => $this->advertiserOrdersUrl($order?->id ? (int) $order->id : null),
                'downloadInvoiceUrl' => $this->advertiserBillingDownloadUrl($this->invoice),
                'dashboardUrl' => route('advertiser.dashboard'),
            ]);

        $this->attachInvoicePdfIfLive($mail, $this->invoice, $this->invoice->invoice_number.'.pdf');
        if ($this->receipt) {
            $this->attachInvoicePdfIfLive($mail, $this->receipt, $this->receipt->invoice_number.'-receipt.pdf');
        }

        return $mail;
    }
}
