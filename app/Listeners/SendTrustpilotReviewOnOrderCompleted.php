<?php

namespace App\Listeners;

use App\Models\Order;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Observes order completion via model updated event (registered in AppServiceProvider).
 * Only sends Trustpilot request — does not replace publisher completion emails.
 *
 * Always schedules after commit: Approve wraps payouts in a transaction, and a
 * failed settings/queue lookup must never abort that money move.
 */
class SendTrustpilotReviewOnOrderCompleted
{
    public function __construct(private EmailNotificationService $emails) {}

    public function handle(Order $order): void
    {
        if ($order->status !== 'completed') {
            return;
        }

        if (! $order->wasChanged('status')) {
            return;
        }

        $orderId = (int) $order->id;
        $userId = (int) ($order->user_id ?? 0);
        if ($orderId < 1 || $userId < 1) {
            return;
        }

        $send = function () use ($orderId, $userId) {
            try {
                $order = Order::with('user')->find($orderId);
                $user = $order?->user;
                if (! $order || ! $user?->email || (int) $user->id !== $userId) {
                    return;
                }

                $this->emails->sendTrustpilotReview($user, $order);
            } catch (\Throwable $e) {
                Log::warning('Trustpilot review email skipped', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);

            return;
        }

        $send();
    }
}
