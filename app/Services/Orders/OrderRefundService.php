<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Returns advertiser funds when an order is cancelled or rejected.
 *
 * Wallet checkouts hold money in reserved_balance, so they are released back to
 * the spendable balance (restoring any promotional portion). Every other payment
 * method was already captured, so the amount is credited to the wallet instead.
 */
class OrderRefundService
{
    public function __construct(private WalletLedgerService $ledger) {}

    /**
     * Cancel an order and return the advertiser's money in one locked transaction.
     *
     * @return bool True when a refund was applied, false when the order was already
     *              cancelled/refunded or nothing had been charged yet.
     */
    public function cancelAndRefund(Order $order, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($order, $reason) {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status === 'cancelled' || $locked->payment_status === 'refunded') {
                return false;
            }

            $amount = round((float) $locked->total_amount, 2);
            $refundable = $locked->payment_status === 'paid' && $amount > 0;

            $locked->update(array_filter([
                'status' => 'cancelled',
                'payment_status' => $refundable ? 'refunded' : null,
            ], fn ($value) => $value !== null));

            if (! $refundable) {
                return false;
            }

            $this->refundToAdvertiser($locked, $amount, $reason);

            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Resolve the refund amount for a rejected line without over-crediting.
     * Single-item orders use the authoritative order total; multi-item orders
     * refund only the rejected line, capped at the order total.
     */
    public function resolveLineRefundAmount(Order $order, float $lineAmount): float
    {
        $order->loadMissing('items');
        $orderTotal = round((float) $order->total_amount, 2);
        $lineAmount = round(abs($lineAmount), 2);

        if ($order->items->count() <= 1) {
            return $orderTotal > 0 ? $orderTotal : $lineAmount;
        }

        if ($lineAmount <= 0) {
            return 0.0;
        }

        return min($lineAmount, max(0.0, $orderTotal));
    }

    /**
     * Move funds back to the advertiser wallet. Must run inside a transaction with
     * the order already locked; throws so the caller's transaction rolls back.
     */
    public function refundToAdvertiser(Order $order, float $amount, ?string $reason = null): bool
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return false;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);

        $bonusRestored = 0.0;
        if ($order->payment_method === 'wallet') {
            $bonusReservedBefore = (float) $wallet->bonus_reserved;
            $wallet->refundReserved($amount);
            $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
        } else {
            $wallet->credit($amount);
        }

        $this->ledger->recordRefund(
            $wallet,
            $amount,
            $bonusRestored,
            $order,
            $order->reference_code ?? $order->order_number
        );

        Log::info('Order refunded to advertiser wallet', [
            'order_id' => $order->id,
            'payment_method' => $order->payment_method,
            'amount' => $amount,
            'bonus_restored' => $bonusRestored,
            'new_balance' => $wallet->balance,
            'new_reserved_balance' => $wallet->reserved_balance,
            'reason' => $reason,
        ]);

        return true;
    }
}
