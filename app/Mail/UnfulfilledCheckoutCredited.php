<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Wallet;
use App\Support\EmailCatalog;

class UnfulfilledCheckoutCredited extends PlatformMailable
{
    public function __construct(
        public User $user,
        public float $amount,
        public string $referenceCode,
        public string $paymentMethod = 'card',
        public ?float $walletBalance = null,
    ) {
        parent::__construct();

        $this->notificationType = 'unfulfilled_checkout_credited';
        $this->recipientUser = $user;
        $this->dedupeKey = 'unfulfilled_checkout_credited:'.$user->id.':'.$referenceCode;
    }

    public function build()
    {
        $amount = number_format(round($this->amount, 2), 2);
        $method = strtolower(trim($this->paymentMethod));
        $methodLabel = Invoice::paymentMethodLabel($method);
        $balance = $this->walletBalance;
        if ($balance === null && ! EmailCatalog::isPreviewUser($this->user)) {
            $advertiserRoleId = Wallet::advertiserRoleId();
            $wallet = $advertiserRoleId
                ? $this->user->wallets()->where('role_id', $advertiserRoleId)->first()
                : null;
            $balance = (float) ($wallet?->balance ?? 0);
        }

        return $this->subject('€'.$amount.' added to your wallet — checkout could not be completed')
            ->markdown('emails.billing.unfulfilled-checkout-credited', [
                'user' => $this->user,
                'amount' => round($this->amount, 2),
                'referenceCode' => $this->referenceCode,
                'paymentMethod' => $method,
                'methodLabel' => $methodLabel,
                'walletBalance' => (float) ($balance ?? 0),
                'balanceUrl' => $this->publicRoute('advertiser.balance'),
                'catalogUrl' => $this->publicRoute('advertiser.catalog'),
            ]);
    }
}
