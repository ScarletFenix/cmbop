<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent wallet credits from a captured PayPal Add Funds order.
 */
class WalletPaypalDepositService
{
    public function __construct(private WalletLedgerService $ledger) {}

    /**
     * Credit from a completed PayPal capture (return URL or webhook).
     *
     * @param  array{id?: string, capture_id?: string, amount?: float, custom?: array{type?: string, user_id?: string, reference_code?: string}, raw?: array<string, mixed>}  $captured
     */
    public function creditFromCapture(array $captured): float
    {
        $custom = is_array($captured['custom'] ?? null) ? $captured['custom'] : [];
        if (($custom['type'] ?? '') !== PaypalCheckoutService::TYPE_WALLET_DEPOSIT) {
            Log::warning('WalletPaypalDepositService: refusing non-wallet capture', [
                'type' => $custom['type'] ?? null,
                'paypal_capture_id' => $captured['capture_id'] ?? null,
            ]);

            return 0.0;
        }

        $userId = (int) ($custom['user_id'] ?? 0);
        $referenceCode = trim((string) ($custom['reference_code'] ?? ''));
        $captureId = trim((string) ($captured['capture_id'] ?? ''));
        $paypalOrderId = trim((string) ($captured['id'] ?? ''));
        $amount = round((float) ($captured['amount'] ?? 0), 2);

        if ($userId <= 0 || $captureId === '' || $amount < 0.01) {
            throw new \RuntimeException('Invalid PayPal wallet deposit capture.');
        }

        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        return $this->withDepositLock($captureId, fn () => $this->creditFromCaptureLocked(
            $userId,
            $captureId,
            $paypalOrderId,
            $amount,
            $referenceCode,
            $captured
        ));
    }

    /**
     * @param  array<string, mixed>  $captured
     */
    private function creditFromCaptureLocked(
        int $userId,
        string $captureId,
        string $paypalOrderId,
        float $amount,
        string $referenceCode,
        array $captured
    ): float {
        $credited = 0.0;
        $notifyDepositId = null;

        DB::transaction(function () use (
            $userId,
            $captureId,
            $paypalOrderId,
            $amount,
            &$referenceCode,
            $captured,
            &$credited,
            &$notifyDepositId
        ) {
            $existing = DepositRequest::query()
                ->where('paypal_capture_id', $captureId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $credited = (float) $existing->amount;

                return;
            }

            if ($paypalOrderId !== '') {
                $byOrder = DepositRequest::query()
                    ->where('paypal_order_id', $paypalOrderId)
                    ->lockForUpdate()
                    ->first();
                if ($byOrder) {
                    if (! $byOrder->paypal_capture_id) {
                        $byOrder->update(['paypal_capture_id' => $captureId]);
                    }
                    $credited = (float) $byOrder->amount;

                    return;
                }
            }

            $ref = $referenceCode !== ''
                ? $referenceCode
                : str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            if (DepositRequest::where('reference_code', $ref)->exists()) {
                do {
                    $ref = str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (DepositRequest::where('reference_code', $ref)->exists());
            }

            try {
                $deposit = DepositRequest::create([
                    'user_id' => $userId,
                    'reference_code' => $ref,
                    'amount' => $amount,
                    'payment_method' => 'paypal',
                    'status' => 'completed',
                    'paypal_order_id' => $paypalOrderId !== '' ? $paypalOrderId : null,
                    'paypal_capture_id' => $captureId,
                    'paypal_response' => $captured['raw'] ?? $captured,
                    'approved_at' => now(),
                    'paid_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintFailure($e)) {
                    throw $e;
                }
                $existing = DepositRequest::query()->where('paypal_capture_id', $captureId)->first()
                    ?: ($paypalOrderId !== ''
                        ? DepositRequest::query()->where('paypal_order_id', $paypalOrderId)->first()
                        : null);
                $credited = (float) ($existing?->amount ?? 0);

                return;
            }

            $this->creditAdvertiserWallet($userId, (float) $deposit->amount, $deposit);
            $credited = (float) $deposit->amount;
            $notifyDepositId = $deposit->id;

            Log::info('Deposit created from PayPal capture', [
                'deposit_id' => $deposit->id,
                'paypal_capture_id' => $captureId,
            ]);
        });

        $this->notifyDepositCredited($notifyDepositId);

        return $credited;
    }

    private function notifyDepositCredited(?int $depositId): void
    {
        if (! $depositId) {
            return;
        }

        $deposit = DepositRequest::with('user')->find($depositId);
        if (! $deposit) {
            return;
        }

        app(DepositSettlementNotifier::class)->notifyApproved($deposit);
    }

    private function creditAdvertiserWallet(int $userId, float $amount, DepositRequest $deposit): void
    {
        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($userId, $advertiserRoleId);
        $wallet->credit($amount);
        $this->ledger->recordDeposit($wallet, $amount, $deposit, 'paypal', $deposit->reference_code);
    }

    private function withDepositLock(string $captureId, callable $callback): mixed
    {
        $key = 'wallet_deposit_pp:'.$captureId;

        try {
            return Cache::lock($key, 20)->block(15, $callback);
        } catch (\BadMethodCallException) {
            return $callback();
        }
    }

    private function isUniqueConstraintFailure(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || $sqlState === '23505'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
