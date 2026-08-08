<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing\InvoicePdfGenerator;

class PaymentFailedMail extends PlatformMailable
{
    public function __construct(public Invoice $document)
    {
        parent::__construct();
        $this->notificationType = 'payment_failed';
        $this->recipientUser = $document->user;
        $this->dedupeKey = 'payment_failed:'.$document->id;
    }

    public function build()
    {
        $order = $this->document->order;
        $reason = data_get($this->document->meta, 'failure_reason')
            ?: $this->document->notes
            ?: 'Payment verification failed.';
        $symbol = config('billing.currency_symbol', '€');

        $retryUrl = $this->advertiserOrdersUrl(
            $order?->id ? (int) $order->id : null,
            ['payment_status' => 'failed']
        );

        $mail = $this->subject('Payment Failed')
            ->markdown('emails.billing.payment-failed', [
                'document' => $this->document,
                'order' => $order,
                'user' => $this->document->user,
                'reason' => $reason,
                'symbol' => $symbol,
                'retryUrl' => $retryUrl,
            ]);

        $path = app(InvoicePdfGenerator::class)->absolutePath($this->document);
        if ($path && is_readable($path)) {
            $mail->attach($path, [
                'as' => $this->document->invoice_number.'-payment-failed.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
