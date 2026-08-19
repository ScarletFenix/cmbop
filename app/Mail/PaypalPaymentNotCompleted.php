<?php

namespace App\Mail;

use App\Models\User;

class PaypalPaymentNotCompleted extends PlatformMailable
{
    public const KIND_CHECKOUT = 'checkout';

    public const KIND_DEPOSIT = 'deposit';

    public const REASON_CANCELLED = 'cancelled';

    public const REASON_DECLINED = 'declined';

    public const REASON_DENIED = 'denied';

    public const REASON_ERROR = 'error';

    public const REASON_PENDING = 'pending';

    public function __construct(
        public User $user,
        public string $kind,
        public string $referenceCode,
        public string $reason,
    ) {
        parent::__construct();

        $this->kind = in_array($kind, [self::KIND_CHECKOUT, self::KIND_DEPOSIT], true)
            ? $kind
            : self::KIND_CHECKOUT;
        $this->reason = in_array($reason, [
            self::REASON_CANCELLED,
            self::REASON_DECLINED,
            self::REASON_DENIED,
            self::REASON_ERROR,
            self::REASON_PENDING,
        ], true) ? $reason : self::REASON_ERROR;

        $this->notificationType = 'paypal_payment_not_completed';
        $this->recipientUser = $user;
        $this->dedupeKey = 'paypal_payment_not_completed:'.$this->kind.':'.$this->referenceCode.':'.$this->reason;
    }

    public function build()
    {
        $pending = $this->reason === self::REASON_PENDING;
        $retryUrl = $this->kind === self::KIND_DEPOSIT
            ? $this->publicRoute('advertiser.add-funds')
            : $this->publicRoute('advertiser.checkout');

        return $this->subject($pending
            ? 'PayPal payment is under review'
            : 'PayPal payment was not completed')
            ->markdown('emails.billing.paypal-payment-not-completed', [
                'user' => $this->user,
                'kind' => $this->kind,
                'referenceCode' => $this->referenceCode,
                'reason' => $this->reason,
                'pending' => $pending,
                'retryUrl' => $retryUrl,
                'retryLabel' => $this->kind === self::KIND_DEPOSIT
                    ? 'Add funds'
                    : 'Return to checkout',
            ]);
    }
}
