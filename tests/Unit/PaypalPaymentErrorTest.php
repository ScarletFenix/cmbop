<?php

namespace Tests\Unit;

use App\Support\PaypalPaymentError;
use App\Support\UserMessages;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

class PaypalPaymentErrorTest extends TestCase
{
    public function test_connection_errors_are_unreachable_not_deposit_or_start(): void
    {
        $e = new ConnectionException(
            'cURL error 7: Failed to connect to api-m.sandbox.paypal.com port 443: Connection refused'
        );

        $this->assertTrue(PaypalPaymentError::isUnreachable($e));
        $this->assertSame(
            UserMessages::get('payment.paypal_unreachable'),
            PaypalPaymentError::startFailure($e)
        );
        $this->assertSame(
            UserMessages::get('payment.paypal_unreachable'),
            PaypalPaymentError::startFailure($e, 'DEPOSIT')
        );
    }

    public function test_curl_timeout_is_unreachable(): void
    {
        $e = new \RuntimeException('cURL error 28: Operation timed out after 10000 milliseconds');

        $this->assertTrue(PaypalPaymentError::isUnreachable($e));
        $this->assertSame(
            UserMessages::get('payment.paypal_unreachable'),
            PaypalPaymentError::startFailure($e)
        );
    }

    public function test_safe_paypal_copy_is_shown_verbatim(): void
    {
        $msg = UserMessages::get('payment.paypal_auth');
        $this->assertSame($msg, PaypalPaymentError::startFailure(new \RuntimeException($msg)));
    }
}
