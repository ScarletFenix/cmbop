<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * PayPal Orders v2 — create, capture, refund, and verify webhooks.
 *
 * Amounts always come from PayPal's payload after capture/refund, never from
 * a client query string. Fail closed when the kill switch is off or credentials
 * are missing.
 */
class PaypalCheckoutService
{
    public const TYPE_ORDER_CHECKOUT = 'order_checkout';

    public const TYPE_WALLET_DEPOSIT = 'wallet_deposit';

    public const CURRENCY = 'EUR';

    public function configured(): bool
    {
        if (! (bool) config('services.paypal.enabled')) {
            return false;
        }

        $id = $this->clientId();
        $secret = $this->secret();

        return $id !== ''
            && $secret !== ''
            && ! str_contains(strtolower($id), 'your-')
            && ! str_contains(strtolower($secret), 'your-');
    }

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('services.paypal.mode', 'sandbox')));

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function baseUrl(): string
    {
        $override = rtrim(trim((string) config('services.paypal.base_url', '')), '/');
        if ($override !== '') {
            return $override;
        }

        return $this->mode() === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public static function formatEuros(float|int|string $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    public static function customId(string $type, int|string $userId, string $referenceCode): string
    {
        return $type.':'.$userId.':'.$referenceCode;
    }

    /**
     * @return array{type: string, user_id: string, reference_code: string}
     */
    public static function parseCustomId(?string $customId): array
    {
        $parts = explode(':', (string) $customId, 3);

        return [
            'type' => $parts[0] ?? '',
            'user_id' => $parts[1] ?? '',
            'reference_code' => $parts[2] ?? '',
        ];
    }

    /**
     * @param  array{type?: string, user_id: int|string, reference_code: string}  $meta
     * @return array{id: string, status: string, approve_url: string, amount: string, currency: string, raw: array<string, mixed>}
     */
    public function createOrder(float $euros, array $meta, string $returnUrl, string $cancelUrl): array
    {
        $this->assertConfigured();

        $amount = self::formatEuros($euros);
        if ((float) $amount < 0.01) {
            throw new RuntimeException('PayPal amount must be greater than €0.');
        }

        $type = (string) ($meta['type'] ?? self::TYPE_ORDER_CHECKOUT);
        $userId = trim((string) ($meta['user_id'] ?? ''));
        $reference = trim((string) ($meta['reference_code'] ?? ''));
        if ($userId === '' || $reference === '') {
            throw new RuntimeException('PayPal order meta requires user_id and reference_code.');
        }
        if (! in_array($type, [self::TYPE_ORDER_CHECKOUT, self::TYPE_WALLET_DEPOSIT], true)) {
            throw new RuntimeException('PayPal order type is not allowed.');
        }

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => self::CURRENCY,
                    'value' => $amount,
                ],
                'custom_id' => self::customId($type, $userId, $reference),
                'invoice_id' => $reference,
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name' => mb_substr((string) config('app.name', 'SEOLinkBuildings'), 0, 127),
            ],
        ];

        $response = $this->paypalRequest('post', '/v2/checkout/orders', $payload, [
            'PayPal-Request-Id' => 'create-'.$reference,
        ]);
        $data = $response->json() ?? [];
        $orderId = (string) ($data['id'] ?? '');
        $approveUrl = $this->linkHref($data, 'approve');

        if ($orderId === '' || $approveUrl === '') {
            throw new RuntimeException('PayPal did not return an approve link.');
        }

        return [
            'id' => $orderId,
            'status' => (string) ($data['status'] ?? ''),
            'approve_url' => $approveUrl,
            'amount' => $amount,
            'currency' => self::CURRENCY,
            'raw' => $data,
        ];
    }

    /**
     * Capture a buyer-approved order. Amount is taken from PayPal, not the client.
     *
     * @return array{id: string, capture_id: string, status: string, amount: float, currency: string, custom: array{type: string, user_id: string, reference_code: string}, raw: array<string, mixed>}
     */
    public function captureOrder(string $paypalOrderId): array
    {
        $this->assertConfigured();

        $paypalOrderId = trim($paypalOrderId);
        if ($paypalOrderId === '') {
            throw new RuntimeException('Missing PayPal order id.');
        }

        $response = $this->paypalRequest(
            'post',
            '/v2/checkout/orders/'.rawurlencode($paypalOrderId).'/capture',
            new \stdClass,
            ['PayPal-Request-Id' => 'capture-'.$paypalOrderId]
        );
        $data = $response->json() ?? [];
        $unit = is_array($data['purchase_units'][0] ?? null) ? $data['purchase_units'][0] : [];
        $capture = is_array($unit['payments']['captures'][0] ?? null) ? $unit['payments']['captures'][0] : [];
        $captureId = trim((string) ($capture['id'] ?? ''));
        $status = strtoupper((string) ($capture['status'] ?? $data['status'] ?? ''));
        $amountRaw = $capture['amount']['value'] ?? $unit['amount']['value'] ?? null;
        $currency = (string) ($capture['amount']['currency_code'] ?? $unit['amount']['currency_code'] ?? self::CURRENCY);
        $custom = self::parseCustomId(isset($unit['custom_id']) ? (string) $unit['custom_id'] : '');

        if ($captureId === '' || $amountRaw === null || $amountRaw === '') {
            throw new RuntimeException('PayPal capture did not return an amount.');
        }
        if ($status !== 'COMPLETED') {
            throw new RuntimeException('PayPal capture was not completed.');
        }
        if (strtoupper($currency) !== self::CURRENCY) {
            throw new RuntimeException('PayPal capture currency is not EUR.');
        }
        if (($custom['user_id'] ?? '') === '') {
            throw new RuntimeException('PayPal capture is missing user_id.');
        }

        return [
            'id' => (string) ($data['id'] ?? $paypalOrderId),
            'capture_id' => $captureId,
            'status' => $status,
            'amount' => round((float) $amountRaw, 2),
            'currency' => self::CURRENCY,
            'custom' => $custom,
            'raw' => $data,
        ];
    }

    /**
     * @return array{id: string, status: string, amount: float, currency: string, raw: array<string, mixed>}
     */
    public function refundCapture(string $captureId, float $euros): array
    {
        $this->assertConfigured();

        $captureId = trim($captureId);
        $amount = self::formatEuros($euros);
        if ($captureId === '') {
            throw new RuntimeException('Missing PayPal capture id.');
        }
        if ((float) $amount < 0.01) {
            throw new RuntimeException('PayPal refund amount must be greater than €0.');
        }

        $response = $this->paypalRequest(
            'post',
            '/v2/payments/captures/'.rawurlencode($captureId).'/refund',
            [
                'amount' => [
                    'currency_code' => self::CURRENCY,
                    'value' => $amount,
                ],
            ],
            ['PayPal-Request-Id' => 'refund-'.$captureId.'-'.$amount]
        );
        $data = $response->json() ?? [];
        $refundId = trim((string) ($data['id'] ?? ''));
        $status = strtoupper((string) ($data['status'] ?? ''));
        $refundedRaw = $data['amount']['value'] ?? $amount;

        if ($refundId === '') {
            throw new RuntimeException('PayPal refund did not return an id.');
        }

        return [
            'id' => $refundId,
            'status' => $status,
            'amount' => round((float) $refundedRaw, 2),
            'currency' => self::CURRENCY,
            'raw' => $data,
        ];
    }

    /**
     * Verify a PayPal webhook. Missing headers / bad cert host / FAILURE → not verified.
     *
     * @return array{verified: bool, event: array<string, mixed>, verification_status?: string, reason?: string}
     */
    public function verifyWebhook(Request $request): array
    {
        $this->assertConfigured();

        $webhookId = trim((string) config('services.paypal.webhook_id', ''));
        if ($webhookId === '') {
            throw new RuntimeException('PayPal webhook is not configured.');
        }

        $algo = (string) $request->header('PAYPAL-AUTH-ALGO', '');
        $certUrl = (string) $request->header('PAYPAL-CERT-URL', '');
        $transmissionId = (string) $request->header('PAYPAL-TRANSMISSION-ID', '');
        $signature = (string) $request->header('PAYPAL-TRANSMISSION-SIG', '');
        $transmissionTime = (string) $request->header('PAYPAL-TRANSMISSION-TIME', '');

        if ($algo === '' || $certUrl === '' || $transmissionId === '' || $signature === '' || $transmissionTime === '') {
            return ['verified' => false, 'event' => [], 'reason' => 'missing_headers'];
        }

        if (! $this->isPaypalCertUrl($certUrl)) {
            return ['verified' => false, 'event' => [], 'reason' => 'invalid_cert_url'];
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return ['verified' => false, 'event' => [], 'reason' => 'invalid_body'];
        }

        $response = $this->paypalRequest('post', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $algo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $signature,
            'transmission_time' => $transmissionTime,
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        $status = strtoupper((string) ($response->json('verification_status') ?? ''));

        return [
            'verified' => $status === 'SUCCESS',
            'event' => $event,
            'verification_status' => $status,
        ];
    }

    public function accessToken(): string
    {
        $this->assertConfigured();

        $cacheKey = 'paypal:oauth:'.$this->mode().':'.hash('sha256', $this->clientId()."\0".$this->secret());
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->withBasicAuth($this->clientId(), $this->secret())
            ->post($this->baseUrl().'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            Log::error('PayPal OAuth failed', ['status' => $response->status()]);
            throw new RuntimeException('PayPal authentication failed.');
        }

        $token = trim((string) $response->json('access_token'));
        if ($token === '') {
            throw new RuntimeException('PayPal authentication failed.');
        }

        $ttl = max(30, ((int) $response->json('expires_in', 300)) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function paypalRequest(string $method, string $path, mixed $body = null, array $headers = []): Response
    {
        $pending = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->withHeaders($headers);

        $url = $this->baseUrl().$path;
        $response = match (strtolower($method)) {
            'post' => $pending->post($url, $body ?? new \stdClass),
            'get' => $pending->get($url),
            default => throw new RuntimeException('Unsupported PayPal method.'),
        };

        if (! $response->successful()) {
            Log::error('PayPal API error', [
                'path' => $path,
                'status' => $response->status(),
            ]);
            throw new RuntimeException('PayPal request failed.');
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function linkHref(array $payload, string $rel): string
    {
        foreach ($payload['links'] ?? [] as $link) {
            if (! is_array($link)) {
                continue;
            }
            if (($link['rel'] ?? '') === $rel && ! empty($link['href'])) {
                return (string) $link['href'];
            }
        }

        return '';
    }

    private function isPaypalCertUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        return $host === 'paypal.com' || str_ends_with($host, '.paypal.com');
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('PayPal is not configured.');
        }
    }

    private function clientId(): string
    {
        return trim((string) config('services.paypal.client_id', ''));
    }

    private function secret(): string
    {
        return trim((string) config('services.paypal.secret', ''));
    }
}
