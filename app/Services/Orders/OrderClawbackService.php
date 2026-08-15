<?php

namespace App\Services\Orders;

use App\Mail\DisputeClawbackPublisher;
use App\Mail\DisputeRefundAdvertiser;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\InAppNotificationService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OrderClawbackService
{
    public function __construct(
        private WalletLedgerService $ledger,
        private InAppNotificationService $notifications,
    ) {}

    public function canOpenDispute(Order $order, ?OrderItem $item = null, bool $asAdmin = false): bool
    {
        if (! OrderItemDispute::tableAvailable()) {
            return false;
        }

        try {
            $this->assertCanOpen($order, $item ?? $order->items->first(), $asAdmin);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function openDispute(OrderItem $item, User $opener, string $reason, bool $asAdmin = false): OrderItemDispute
    {
        $reason = trim($reason);
        if (strlen($reason) < 10 || strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Please provide a reason between 10 and 1000 characters.',
            ]);
        }

        return DB::transaction(function () use ($item, $opener, $reason, $asAdmin) {
            $item = OrderItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $order = Order::where('id', $item->order_id)->lockForUpdate()->firstOrFail();

            if (! $asAdmin && (int) $order->user_id !== (int) $opener->id) {
                throw ValidationException::withMessages([
                    'order' => 'Unauthorized: this order does not belong to you.',
                ]);
            }

            $this->assertCanOpen($order, $item, $asAdmin);

            $dispute = OrderItemDispute::create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'opened_by' => $opener->id,
                'status' => OrderItemDispute::STATUS_OPEN,
                'reason' => $reason,
            ]);

            $this->notifications->notifyDisputeOpened($dispute->fresh(['order', 'orderItem.site']));

            return $dispute;
        });
    }

    public function dismiss(OrderItemDispute $dispute, User $admin, string $notes): OrderItemDispute
    {
        $notes = trim($notes);
        if (strlen($notes) < 10 || strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'admin_notes' => 'Please provide resolution notes between 10 and 1000 characters.',
            ]);
        }

        return DB::transaction(function () use ($dispute, $admin, $notes) {
            $dispute = OrderItemDispute::where('id', $dispute->id)->lockForUpdate()->firstOrFail();
            if (! $dispute->isOpen()) {
                throw ValidationException::withMessages([
                    'dispute' => 'Only open disputes can be dismissed.',
                ]);
            }

            $dispute->update([
                'status' => OrderItemDispute::STATUS_DISMISSED,
                'admin_notes' => $notes,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            $fresh = $dispute->fresh(['order', 'orderItem.site']);
            $this->notifications->notifyDisputeDismissed($fresh);

            return $fresh;
        });
    }

    public function uphold(OrderItemDispute $dispute, User $admin, string $notes): OrderItemDispute
    {
        $notes = trim($notes);
        if (strlen($notes) < 10 || strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'admin_notes' => 'Please provide resolution notes between 10 and 1000 characters.',
            ]);
        }

        return DB::transaction(function () use ($dispute, $admin, $notes) {
            $dispute = OrderItemDispute::where('id', $dispute->id)->lockForUpdate()->firstOrFail();
            if (! $dispute->isOpen()) {
                throw ValidationException::withMessages([
                    'dispute' => 'Only open disputes can be upheld.',
                ]);
            }

            $item = OrderItem::where('id', $dispute->order_item_id)->lockForUpdate()->firstOrFail();
            $order = Order::where('id', $dispute->order_id)->lockForUpdate()->firstOrFail();

            $existingUpheld = OrderItemDispute::where('order_item_id', $item->id)
                ->where('status', OrderItemDispute::STATUS_UPHELD)
                ->where('id', '!=', $dispute->id)
                ->exists();
            if ($existingUpheld || $order->payment_status === 'refunded') {
                throw ValidationException::withMessages([
                    'dispute' => 'This order item has already been clawed back or refunded.',
                ]);
            }

            $targetPayout = round((float) $item->publisherPayoutAmount(), 2);
            $advertiserCredit = round((float) $item->price, 2);

            $site = Site::find($item->site_id);
            $publisherId = $site?->publisher_id;
            $publisherRoleId = Wallet::publisherRoleId();
            $advertiserRoleId = Wallet::advertiserRoleId();

            $debited = 0.0;
            $debtCreated = 0.0;
            $publisherWallet = null;

            if ($publisherId && $publisherRoleId && $targetPayout > 0) {
                $publisherWallet = Wallet::lockOrCreateForRole((int) $publisherId, (int) $publisherRoleId);
                $available = $publisherWallet->withdrawableBalance();
                $debited = round(min($available, $targetPayout), 2);
                $debtCreated = round(max(0, $targetPayout - $debited), 2);

                if ($debited > 0) {
                    $publisherWallet->deductWithdrawable($debited);
                    $this->ledger->recordTransferOut(
                        $publisherWallet,
                        $debited,
                        $item,
                        'CLAWBACK-ITEM-'.$item->id,
                        'Clawback for order #'.($order->order_number ?? $order->id),
                        [
                            'order_id' => $order->id,
                            'dispute_id' => $dispute->id,
                            'target_payout' => $targetPayout,
                            'debt_created' => $debtCreated,
                            'advertiser_credited' => $advertiserCredit,
                        ]
                    );
                }

                if ($debtCreated > 0) {
                    $publisherWallet->increaseDebt($debtCreated);
                }
            }

            if ($advertiserRoleId && $advertiserCredit > 0) {
                $advertiserWallet = Wallet::lockOrCreateForRole((int) $order->user_id, (int) $advertiserRoleId);
                $bonusShare = $this->bonusShareFromPurchaseLedger($advertiserWallet, $order, $advertiserCredit);
                $cashShare = round($advertiserCredit - $bonusShare, 2);
                if ($bonusShare > 0) {
                    $advertiserWallet->creditBonus($bonusShare);
                }
                if ($cashShare > 0) {
                    $advertiserWallet->credit($cashShare);
                }
                $this->ledger->recordRefund(
                    $advertiserWallet,
                    $advertiserCredit,
                    $bonusShare,
                    $order,
                    $order->reference_code ?: 'CLAWBACK-REFUND-'.$item->id
                );
            }

            $dispute->update([
                'status' => OrderItemDispute::STATUS_UPHELD,
                'admin_notes' => $notes,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'publisher_debited' => $debited,
                'advertiser_credited' => $advertiserCredit,
                'debt_created' => $debtCreated,
            ]);

            if ($this->everyItemHasBeenClawedBack($order)) {
                $order->update([
                    'payment_status' => 'refunded',
                ]);
            }

            if ($site) {
                Site::refreshCompletedOrdersCount((int) $site->id);
            }

            $fresh = $dispute->fresh(['order', 'orderItem.site', 'order.user']);
            $this->notifications->notifyDisputeUpheld($fresh);

            $publisher = $publisherId ? User::find($publisherId) : null;
            $advertiser = $order->user ?? User::find($order->user_id);

            if ($publisher?->email) {
                try {
                    Mail::to($publisher->email)->send(new DisputeClawbackPublisher($fresh, $publisher, $debited, $debtCreated));
                } catch (\Throwable $e) {
                    Log::warning('Dispute clawback publisher mail failed', [
                        'dispute_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($advertiser?->email) {
                try {
                    Mail::to($advertiser->email)->send(new DisputeRefundAdvertiser($fresh, $advertiser, $advertiserCredit));
                } catch (\Throwable $e) {
                    Log::warning('Dispute refund advertiser mail failed', [
                        'dispute_id' => $fresh->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            ContentSubmission::releaseAllForOrderItem((int) $item->id);

            Log::info('Order item dispute upheld / clawback applied', [
                'dispute_id' => $fresh->id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'publisher_debited' => $debited,
                'debt_created' => $debtCreated,
                'advertiser_credited' => $advertiserCredit,
            ]);

            return $fresh;
        });
    }

    public function clearWalletDebt(Wallet $wallet, User $admin, string $reason): float
    {
        $reason = trim($reason);
        if (strlen($reason) < 5 || strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Please provide a reason between 5 and 1000 characters.',
            ]);
        }

        return DB::transaction(function () use ($wallet, $admin, $reason) {
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
            $cleared = $wallet->clearDebt();
            if ($cleared <= 0) {
                throw ValidationException::withMessages([
                    'debt' => 'This wallet has no outstanding debt.',
                ]);
            }

            $this->ledger->recordAdjustment(
                $wallet,
                $cleared,
                'credit',
                null,
                'DEBT-CLEAR-'.$wallet->id,
                'Admin cleared publisher debt',
                [
                    'cleared_by' => $admin->id,
                    'reason' => $reason,
                    'previous_debt' => $cleared,
                ]
            );

            return $cleared;
        });
    }

    /**
     * Completed orders already consumed reserved bonus. Restore that slice as
     * spend-only so a clawback cannot turn welcome credit into withdrawable cash.
     */
    private function bonusShareFromPurchaseLedger(Wallet $wallet, Order $order, float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return 0.0;
        }

        $reference = (string) ($order->reference_code ?: $order->order_number);
        $purchasedBonus = (float) WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where(function ($query) use ($order, $reference) {
                $query->where(function ($related) use ($order) {
                    $related->where('related_type', $order->getMorphClass())
                        ->where('related_id', $order->id);
                });
                if ($reference !== '') {
                    $query->orWhere('reference', $reference);
                }
            })
            ->sum('bonus_amount');

        $alreadyRestored = (float) WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransaction::TYPE_REFUND)
            ->where(function ($query) use ($order, $reference) {
                $query->where(function ($related) use ($order) {
                    $related->where('related_type', $order->getMorphClass())
                        ->where('related_id', $order->id);
                });
                if ($reference !== '') {
                    $query->orWhere('reference', $reference);
                }
            })
            ->sum('bonus_amount');

        $remaining = max(0, round($purchasedBonus - $alreadyRestored, 2));

        return min($amount, $remaining);
    }

    /**
     * @throws ValidationException
     */
    protected function assertCanOpen(Order $order, ?OrderItem $item, bool $asAdmin = false): void
    {
        if (! OrderItemDispute::tableAvailable()) {
            throw ValidationException::withMessages([
                'order' => 'Link removal reports are temporarily unavailable. Please contact support.',
            ]);
        }

        if (! $item) {
            throw ValidationException::withMessages([
                'order' => 'Order has no items to dispute.',
            ]);
        }

        if ($order->status !== 'completed') {
            throw ValidationException::withMessages([
                'order' => 'Only completed orders can be disputed.',
            ]);
        }

        if ($order->payment_status === 'refunded') {
            throw ValidationException::withMessages([
                'order' => 'This order has already been refunded.',
            ]);
        }

        if (! $asAdmin) {
            $completedAt = $order->completed_at ?? $order->updated_at;
            if (! $completedAt || $completedAt->lt(now()->subDays(OrderItemDispute::REPORT_WINDOW_DAYS))) {
                throw ValidationException::withMessages([
                    'order' => 'The '.OrderItemDispute::REPORT_WINDOW_DAYS.'-day report window has expired.',
                ]);
            }
        }

        $blocking = OrderItemDispute::where('order_item_id', $item->id)
            ->whereIn('status', [OrderItemDispute::STATUS_OPEN, OrderItemDispute::STATUS_UPHELD])
            ->exists();

        if ($blocking) {
            throw ValidationException::withMessages([
                'order' => 'A dispute is already open or was already upheld for this placement.',
            ]);
        }
    }

    private function everyItemHasBeenClawedBack(Order $order): bool
    {
        $itemIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return false;
        }

        $upheldItemIds = OrderItemDispute::query()
            ->where('order_id', $order->id)
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->pluck('order_item_id')
            ->unique();

        return $itemIds->every(fn ($id) => $upheldItemIds->contains($id));
    }
}
