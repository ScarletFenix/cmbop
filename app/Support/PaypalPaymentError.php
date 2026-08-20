<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Shared PayPal start-failure copy for checkout and Add Funds.
 */
final class PaypalPaymentError
{
    public static function startFailure(\Throwable $e, string $fallbackCode = 'START'): string
    {
        if (self::isUnreachable($e)) {
            Log::error('PayPal connection failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return UserMessages::get('payment.paypal_unreachable');
        }

        if (UserFacingError::isSafe($e)) {
            return trim($e->getMessage());
        }

        $code = match (true) {
            $e instanceof QueryException, $e instanceof \PDOException => 'SQL',
            $e instanceof \TypeError => 'TYPE',
            $e instanceof \ErrorException => 'PHP',
            $e instanceof \Error => 'ERR',
            default => $fallbackCode,
        };

        return UserFacingError::message($e, UserMessages::get('payment.paypal_rejected', ['code' => $code]));
    }

    public static function isUnreachable(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || str_contains($e->getMessage(), 'cURL')
            || str_contains(strtolower($e->getMessage()), 'connection refused');
    }
}
