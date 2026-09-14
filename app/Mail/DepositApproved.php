<?php

namespace App\Mail;

use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Models\Wallet;
use App\Services\Billing\DepositReceiptService;
use App\Support\EmailCatalog;

class DepositApproved extends PlatformMailable
{
    public DepositRequest $deposit;

    public function __construct(DepositRequest $deposit)
    {
        parent::__construct();

        $deposit->loadMissing('user');
        $this->deposit = $deposit;
        $this->notificationType = 'deposit_approved';
        $this->recipientUser = $deposit->user;
        $this->dedupeKey = 'deposit_approved:'.$deposit->id;
    }

    public function build()
    {
        $deposit = $this->deposit->loadMissing('user');
        $method = strtolower((string) ($deposit->payment_method ?? ''));
        $isCard = $method === 'card';
        $isPaypal = $method === 'paypal';
        $isInstant = $isCard || $isPaypal;
        $amount = number_format((float) $deposit->amount, 2);
        $receipt = $this->resolveReceipt($deposit);

        $subject = $isInstant
            ? 'Wallet topped up — €'.$amount
            : 'Deposit Approved - €'.$amount;

        $advertiserWallet = null;
        if ($deposit->user && ! EmailCatalog::isPreviewUser($deposit->user)) {
            $advertiserRoleId = Wallet::advertiserRoleId();
            $advertiserWallet = $advertiserRoleId
                ? $deposit->user->wallets()->where('role_id', $advertiserRoleId)->first()
                : null;
        }

        $mail = $this->subject($subject)
            ->markdown('emails.deposit-approved', [
                'deposit' => $deposit,
                'isCard' => $isCard,
                'isPaypal' => $isPaypal,
                'isInstant' => $isInstant,
                'receipt' => $receipt,
                'walletBalance' => (float) ($advertiserWallet?->balance ?? 0),
                'balanceUrl' => $this->customerFacingRoute('advertiser.balance'),
                'downloadReceiptUrl' => $receipt
                    ? $this->advertiserBillingDownloadUrl($receipt)
                    : null,
            ]);

        if ($receipt) {
            $this->attachInvoicePdfIfLive($mail, $receipt, $receipt->invoice_number.'.pdf');
        }

        return $mail;
    }

    protected function resolveReceipt(DepositRequest $deposit): ?Invoice
    {
        if ((int) $deposit->id === 0 || $deposit->reference_code === 'DEP-PREVIEW') {
            return null;
        }

        try {
            return app(DepositReceiptService::class)->issue($deposit);
        } catch (\Throwable) {
            return app(DepositReceiptService::class)->find($deposit);
        }
    }
}
