<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Thrown when a withdrawal refund/cancel cannot identify the debited wallet.
 */
class ManualWithdrawalUnknownWalletException extends RuntimeException
{
    public static function forWithdrawal(int $withdrawalId): self
    {
        return new self(
            'Cannot refund withdrawal #'.$withdrawalId.': the source wallet is unknown.'
        );
    }
}
