<?php

namespace App\Mail;

use App\Models\DepositRequest;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Support\MailNotificationPresenter;

/**
 * Admin alert: the advertiser says the transfer has left their bank. Nothing is
 * credited yet — an admin still has to match it against the account.
 *
 * Primary CTA is a signed approve-confirm link (confirm UI, then credit). The
 * deposits list remains a secondary escape hatch.
 */
class DepositMarkedPaid extends PlatformMailable
{
    public function __construct(
        public DepositRequest $deposit,
    ) {
        parent::__construct();

        // Leave dedupeKey to the base class: it keys on the recipient as well as
        // the deposit, so a fan-out to several admins is not treated as one send.
        $this->notificationType = 'deposit_marked_paid';
    }

    public function build()
    {
        return $this->subject('Payment reported — '.MailNotificationPresenter::euro((float) $this->deposit->amount).' deposit awaiting confirmation')
            ->markdown('emails.deposit-marked-paid')
            ->with([
                'deposit' => $this->deposit,
                'user' => $this->deposit->user,
                'approveUrl' => ManualDepositApproveLink::url($this->deposit),
                'adminUrl' => $this->publicRoute('admin.deposits'),
            ]);
    }
}
