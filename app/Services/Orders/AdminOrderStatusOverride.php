<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Lets an admin unstick an order that is sitting in the wrong stage.
 *
 * Deliberately limited to the stages that move no money. Completing an order
 * pays the publisher and releases the advertiser's reserved balance, and
 * cancelling refunds it — that logic lives in the approve and refund paths, so
 * reaching those states by editing a status field would tell both parties the
 * order had settled while nobody was actually paid.
 */
class AdminOrderStatusOverride
{
    /** @var list<string> */
    public const ALLOWED_STATUSES = ['pending', 'processing', 'review'];

    public function __construct(private InAppNotificationService $notifications) {}

    /**
     * @return list<string>
     */
    public function availableFor(Order $order): array
    {
        if (! $this->isOverridable($order)) {
            return [];
        }

        return array_values(array_filter(
            self::ALLOWED_STATUSES,
            fn (string $status): bool => $status !== $order->status
        ));
    }

    public function isOverridable(Order $order): bool
    {
        return in_array($order->status, self::ALLOWED_STATUSES, true)
            && $order->payment_status === 'paid';
    }

    /**
     * @throws ValidationException
     */
    public function apply(Order $order, string $target, User $admin, string $reason): Order
    {
        $this->assertCanMove($order, $target);

        $from = (string) $order->status;

        DB::transaction(function () use ($order, $target) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            $this->assertCanMove($locked, $target);

            $locked->update(['status' => $target]);

            if ($target === 'review') {
                $this->restartReviewWindow($locked);
            }
        });

        $fresh = $order->fresh(['items', 'user']);

        ActivityLogger::tryLog(
            'order.status_overridden',
            $admin->name.' moved order '.$order->order_number." from {$from} to {$target}",
            $fresh,
            ['from' => $from, 'to' => $target, 'reason' => $reason],
            $order->order_number
        );

        $this->announce($fresh, $from, $target, $reason);

        return $fresh;
    }

    /**
     * @throws ValidationException
     */
    private function assertCanMove(Order $order, string $target): void
    {
        if (! in_array($target, self::ALLOWED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Completing or cancelling an order moves money, so it has to go through approval or a refund rather than a status change.',
            ]);
        }

        if (! in_array($order->status, self::ALLOWED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'This order is already '.$order->status.' and cannot be reopened from here.',
            ]);
        }

        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'status' => 'Only a paid order can be moved between stages. Settle the payment first.',
            ]);
        }

        if ($order->status === $target) {
            throw ValidationException::withMessages([
                'status' => 'This order is already at that stage.',
            ]);
        }
    }

    /**
     * Auto-approve fires on the review clock, so an order pushed back into
     * review with a stale timestamp could complete and pay out within minutes.
     * Give the advertiser the full window instead.
     */
    private function restartReviewWindow(Order $order): void
    {
        OrderItem::restartAutoApproveClocksForOrder((int) $order->id);
    }

    private function announce(Order $order, string $from, string $target, string $reason): void
    {
        $summary = "Support moved order #{$order->order_number} from {$from} to {$target}. Reason: {$reason}";

        try {
            $this->notifications->notifyOrderStatusOverridden($order, $summary);
        } catch (\Throwable $e) {
            Log::warning('Failed to announce admin order status override', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    public static function publisherIdsFor(Order $order): array
    {
        return Site::whereIn('id', $order->items->pluck('site_id')->filter())
            ->pluck('publisher_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
