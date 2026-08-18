<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaypalWebhookLog;
use App\Services\OrderPaymentService;
use App\Services\PaypalCheckoutService;
use App\Services\WalletPaypalDepositService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaypalWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $paypal = app(PaypalCheckoutService::class);
        if (! $paypal->configured() || trim((string) config('services.paypal.webhook_id', '')) === '') {
            Log::error('PayPal webhook is not configured');

            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        try {
            $verified = $paypal->verifyWebhook($request);
            if (! ($verified['verified'] ?? false)) {
                Log::warning('PayPal webhook not verified', [
                    'reason' => $verified['reason'] ?? $verified['verification_status'] ?? null,
                ]);

                return response()->json(['error' => 'Invalid signature'], 400);
            }

            $event = is_array($verified['event'] ?? null) ? $verified['event'] : [];
            $eventId = trim((string) ($event['id'] ?? ''));
            $eventType = (string) ($event['event_type'] ?? '');
            if ($eventId === '') {
                return response()->json(['error' => 'Missing event id'], 400);
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
                    'payload' => $event,
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

            return response()->json(['error' => $e->getMessage()], 500);
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

        Log::info('PayPal capture refund stamped on orders', [
            'paypal_capture_id' => $refunded['capture_id'],
            'paypal_refund_id' => $refunded['refund_id'],
            'orders_updated' => $updated->count(),
        ]);
    }
}
