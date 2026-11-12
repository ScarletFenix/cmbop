<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Withdrawal;
use App\Support\MailNotificationPresenter;

class PartnerPayoutReleasedMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public Withdrawal $withdrawal,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_payout_released';
        $this->dedupeKey = 'partner-payout-'.$withdrawal->id;
        $this->skipUserPreference = true;
    }

    public function build()
    {
        $amount = (float) ($this->withdrawal->net_amount ?? $this->withdrawal->amount);
        $currency = strtoupper((string) ($this->withdrawal->currency ?: 'EUR'));
        $label = $currency === 'EUR'
            ? MailNotificationPresenter::euro($amount)
            : $currency.' '.MailNotificationPresenter::euro($amount, '');

        return $this->subject('Payout released: '.$label)
            ->markdown('emails.partner.payout-released')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'amountLabel' => $label,
                'actionUrl' => $this->loginUrl(),
                'moneyUrl' => $this->moneyUrl(),
            ]);
    }
}
