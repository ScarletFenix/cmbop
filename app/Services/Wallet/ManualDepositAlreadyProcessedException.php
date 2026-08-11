<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Thrown when a manual deposit approve is attempted on a non-pending request.
 */
class ManualDepositAlreadyProcessedException extends RuntimeException
{
    public static function forDeposit(int $depositId): self
    {
        return new self('This deposit request has already been processed.', 0, null);
    }
}
