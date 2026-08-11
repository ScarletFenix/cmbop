<?php

namespace App\Services\Wallet;

use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Temporary signed URLs for admin email → confirm-before-mark-paid flow.
 */
class ManualWithdrawalMarkPaidLink
{
    public static function expireMinutes(): int
    {
        $minutes = (int) config('billing.withdrawal_mark_paid_link_expire_minutes', 60 * 24 * 7);

        return max(1, $minutes);
    }

    public static function expiresAt(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addMinutes(self::expireMinutes());
    }

    public static function url(Withdrawal|int $withdrawal): string
    {
        $id = $withdrawal instanceof Withdrawal ? (int) $withdrawal->id : (int) $withdrawal;

        $relative = URL::temporarySignedRoute(
            'admin.withdrawals.mark-paid-confirm.show',
            self::expiresAt(),
            ['withdrawal' => $id],
            absolute: false
        );

        return rtrim(app_public_url(), '/').$relative;
    }
}
