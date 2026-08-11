<?php

namespace App\Services\Wallet;

use RuntimeException;

/**
 * Thrown when a withdrawal status transition is not allowed.
 */
class ManualWithdrawalInvalidTransitionException extends RuntimeException
{
    public static function messageFor(string $message): self
    {
        return new self($message);
    }
}
