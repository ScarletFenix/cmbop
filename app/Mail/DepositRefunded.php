<?php

namespace App\Mail;

use App\Models\DepositRequest;
use App\Models\Invoice;

class DepositRefunded extends PlatformMailable
{
    public DepositRequest $deposit;

    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();

        $deposit->loadMissing('user');
        $this->deposit = $deposit;
        $this->notificationType = 'deposit_refunded';
        $this->recipientUser = $deposit->user;
        $this->dedupeKey = 'deposit_refunded:'.$deposit->id;
    }

    public function build()
    {
        $deposit = $this->deposit->loadMissing('user');
        $amount = number_format((float) $deposit->amount, 2);
        $response = is_array($deposit->paypal_response) ? $deposit->paypal_response : [];
        $debt = round((float) ($response['refund']['debt_created'] ?? 0), 2);

        return $this->subject('PayPal deposit refunded — €'.$amount)
            ->markdown('emails.deposit-refunded', [
                'deposit' => $deposit,
                'debt' => $debt,
                'methodLabel' => Invoice::paymentMethodLabel($deposit->payment_method),
                'balanceUrl' => route('advertiser.balance'),
            ]);
    }
}
