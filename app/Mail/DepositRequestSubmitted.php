<?php

namespace App\Mail;

use App\Models\DepositRequest;
use App\Services\Wallet\ManualDepositApproveLink;
use App\Support\MailNotificationPresenter;

/**
 * Admin alert: a new manual deposit request was created. Primary CTA opens the
 * signed approve-confirm page (nothing credits until the admin confirms).
 */
class DepositRequestSubmitted extends PlatformMailable
{
    public $deposit;

    public $user;

    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();
        $this->deposit = $deposit;
        $this->user = $deposit->user;
        $this->notificationType = 'deposit_submitted';
    }

    public function build()
    {
        return $this->subject('New Deposit Request - '.MailNotificationPresenter::euro((float) $this->deposit->amount))
            ->markdown('emails.deposit-request-submitted')
            ->with([
                'deposit' => $this->deposit,
                'user' => $this->user,
                'approveUrl' => ManualDepositApproveLink::url($this->deposit),
                'adminUrl' => $this->publicRoute('admin.deposits'),
            ]);
    }
}
