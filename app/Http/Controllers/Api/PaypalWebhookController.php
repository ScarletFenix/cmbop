<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaypalWebhookLog;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use App\Services\PaypalCheckoutService;
use App\Services\WalletPaypalDepositService;
use App\Support\UserMessages;
use App\Support\WebhookPayloadRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaypalWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $paypal = app(PaypalCheckoutService::class);
        if (! $paypal->configured() || trim((string) config('services.paypal.webhook_id', '')) === '') {
            Log::error('PayPal webhook is not configured');

            return response()->json(['error' => UserMessages::get('payment.webhook_unavailable')], 503);
        }

        try {
            $verified = $paypal->verifyWebhook($request);
            if (! ($verified['verified'] ?? false)) {
                Log::warning('PayPal webhook not verified', [
                    'reason' => $verified['reason'] ?? $verified['verification_status'] ?? null,
                ]);

                return response()->json(['error' => UserMessages::get('payment.webhook_signature')], 400);
            }

            $event = is_array($verified['event'] ?? null) ? $verified['event'] : [];
            $eventId = trim((string) ($event['id'] ?? ''));
            $eventType = (string) ($event['event_type'] ?? '');
            if ($eventId === '') {
                return response()->json(['error' => UserMessages::get('payment.webhook_event')], 400);
            }

            Log::info('Processing PayPal webhook event', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            $existingLog = PaypalWebhookLog::query()->where('event_id', $eventId)->first();
            if ($existingLog && $existingLog->processed) {
                return response()->json(['status' => 'duplicate'], 200);
            }
            if (! $existingLog) {
                PaypalWebhookLog::create([
                    'event_id' => $eventId,
                    'event_type' => $eventType !== '' ? $eventType : 'unknown',
                    'payload' => WebhookPayloadRedactor::paypal($event),
                    'processed' => false,
                ]);
            }

            $this->routeEvent($eventType, $event, $paypal);

            PaypalWebhookLog::query()->where('event_id', $eventId)->update(['processed' => true]);

            return response()->json(['status' => 'success'], 200);
        } catch (\Throwable $e) {
            Log::error('PayPal webhook error: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);

            return response()->json(['error' => UserMessages::get('payment.webhook_failed')], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function routeEvent(string $eventType, array $event, PaypalCheckoutService $paypal): void
    {
        $type = strtoupper($eventType);

        if (in_array($type, [
            'PAYMENT.CAPTURE.COMPLETED',
            'CHECKOUT.ORDER.APPROVED',
            'CHECKOUT.ORDER.COMPLETED',
        ], true)) {
            $this->settleCapture($event, $paypal);

            return;
        }

        if ($type === 'PAYMENT.CAPTURE.REFUNDED') {
            $this->handleCaptureRefunded($event, $paypal);

            return;
        }

        if (in_array($type, [
            'PAYMENT.CAPTURE.DENIED',
            'PAYMENT.CAPTURE.DECLINED',
        ], true)) {
            Log::info('PayPal capture was not completed', [
                'event_type' => $type,
                'event_id' => $event['id'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function settleCapture(array $event, PaypalCheckoutService $paypal): void
    {
        $captured = $paypal->captureFromWebhookEvent($event);
        if ($captured === null) {
            throw new \RuntimeException('PayPal webhook did not include a completed capture.');
        }

        $custom = is_array($captured['custom'] ?? null) ? $captured['custom'] : [];
        if (($custom['type'] ?? '') === PaypalCheckoutService::TYPE_WALLET_DEPOSIT) {
            $credited = app(WalletPaypalDepositService::class)->creditFromCapture($captured);
            Log::info('PayPal wallet deposit settled via webhook', [
                'paypal_capture_id' => $captured['capture_id'] ?? null,
                'credited' => $credited,
            ]);

            return;
        }
        if (($custom['type'] ?? '') !== PaypalCheckoutService::TYPE_ORDER_CHECKOUT) {
            throw new \RuntimeException('PayPal webhook is not an order checkout.');
        }

        $referenceCode = trim((string) ($custom['reference_code'] ?? ''));
        if ($referenceCode === '') {
            throw new \RuntimeException('PayPal webhook is missing reference_code.');
        }

        $paymentService = app(OrderPaymentService::class);
        $newlyPaid = $paymentService->finalizePaypalCheckout($referenceCode, $captured);
        if ($newlyPaid->isNotEmpty()) {
            $paymentService->notifyPublishersOfPaidOrders($newlyPaid);
        }

        Log::info('PayPal checkout settled via webhook', [
            'reference_code' => $referenceCode,
            'paypal_capture_id' => $captured['capture_id'] ?? null,
            'orders_updated' => $newlyPaid->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleCaptureRefunded(array $event, PaypalCheckoutService $paypal): void
    {
        $refunded = $paypal->refundFromWebhookEvent($event);
        if ($refunded === null) {
            throw new \RuntimeException('PayPal refund webhook is missing capture or refund id.');
        }

        $updated = app(OrderPaymentService::class)->markPaypalCaptureRefunded(
            $refunded['capture_id'],
            $refunded['refund_id'],
            $refunded['paypal_order_id']
        );

        $bonusRestored = 0.0;
        $refunds = app(OrderRefundService::class);
        foreach ($updated as $order) {
            if (($order->payment_status ?? '') !== 'refunded') {
                continue;
            }

            try {
                $bonusRestored += $refunds->restoreCheckoutBonusAfterExternalPaypalRefund($order);
            } catch (\Throwable $e) {
                Log::error('PayPal refund webhook could not restore checkout bonus', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('PayPal capture refund stamped on orders', [
            'paypal_capture_id' => $refunded['capture_id'],
            'paypal_refund_id' => $refunded['refund_id'],
            'orders_updated' => $updated->count(),
            'bonus_restored' => $bonusRestored,
        ]);

        if ($updated->isEmpty()) {
            $debited = app(WalletPaypalDepositService::class)->reverseFromRefund(
                (string) ($refunded['capture_id'] ?? ''),
                (string) ($refunded['refund_id'] ?? ''),
                (string) ($refunded['paypal_order_id'] ?? ''),
                (float) ($refunded['amount'] ?? 0)
            );
            Log::info('PayPal capture refund applied to Add Funds deposit', [
                'paypal_capture_id' => $refunded['capture_id'] ?? null,
                'paypal_refund_id' => $refunded['refund_id'] ?? null,
                'wallet_debited' => $debited,
            ]);
        }
    }
}
