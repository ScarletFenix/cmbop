<?php

namespace App\Listeners;

use App\Models\DepositRequest;
use App\Services\Billing\DepositReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hooks DepositRequest model events so every settled top-up gets its receipt,
 * whether it was approved by an admin or credited straight from Stripe.
 * Runs after commit so the wallet credit and deposit row are both durable.
 */
class IssueDepositReceipt
{
    public function __construct(private DepositReceiptService $receipts) {}

    public function created(DepositRequest $deposit): void
    {
        if (! $this->receipts->isSettled($deposit)) {
            return;
        }

        $this->issue($deposit->id);
    }

    public function updated(DepositRequest $deposit): void
    {
        if (! $deposit->wasChanged('status') || ! $this->receipts->isSettled($deposit)) {
            return;
        }

        $this->issue($deposit->id);
    }

    private function issue(int $depositId): void
    {
        $this->afterCommit(function () use ($depositId) {
            try {
                $deposit = DepositRequest::with('user')->find($depositId);

                if ($deposit) {
                    $this->receipts->issue($deposit);
                }
            } catch (\Throwable $e) {
                Log::warning('Deposit receipt hook failed', [
                    'deposit_request_id' => $depositId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
