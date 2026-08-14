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
     * Resolve the refund amount when an order is cancelled entirely.
     * Prefer the authoritative order total; fall back to the sum of line prices.
     */
    public function resolveOrderCancelRefundAmount(Order $order): float
    {
        $orderTotal = round((float) $order->total_amount, 2);
        if ($orderTotal > 0) {
            return $orderTotal;
        }

        $order->loadMissing('items');

        return round(abs((float) $order->items->sum('price')), 2);
    }

    /**
     * Resolve the refund amount for a rejected line without over-crediting.
     * Single-item orders use the authoritative order total; multi-item orders
     * refund only the rejected line, capped at the order total.
     *
     * Prefer resolveOrderCancelRefundAmount() when the whole order is cancelled.
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
            // Card / Wise / bank / crypto may still hold leftover checkout bonus
            // in reserved. Restore that slice as spend-only; credit only the
            // captured cash. Crediting the full line minted withdrawable cash
            // from promotional credit.
            $bonusShare = min($amount, max(0, round((float) $wallet->bonus_reserved, 2)));
            $cashShare = round($amount - $bonusShare, 2);
            if ($bonusShare > 0) {
                $bonusReservedBefore = (float) $wallet->bonus_reserved;
                $wallet->refundReserved($bonusShare);
                $bonusRestored = max(0, round($bonusReservedBefore - (float) $wallet->bonus_reserved, 2));
            }
            if ($cashShare > 0) {
                $wallet->credit($cashShare);
            }
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

    /**
     * Drop reserved funds when an order is completed.
     * Wallet checkouts consume the full line. Card / manual checkouts only
     * consume leftover promotional reserve so it cannot be refunded as cash later.
     */
    public function consumeReservedForSettledOrder(Order $order, Wallet $wallet): void
    {
        $total = round((float) $order->total_amount, 2);
        if ($total <= 0) {
            return;
        }

        if ($order->payment_method === 'wallet') {
            $wallet->consumeReserved($total);

            return;
        }

        $bonus = min($total, max(0, round((float) $wallet->bonus_reserved, 2)));
        if ($bonus > 0) {
            $wallet->consumeReserved($bonus);
        }
    }
}
