<?php

namespace App\Support;

use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Services\InAppNotificationService;
use App\Services\Orders\ManagedFulfilmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Live order state for in-app bells: action-needed flags and stale-task cleanup.
 *
 * Action-needed for advertisers/publishers uses the same predicates as the
 * Orders / Tasks lists (AdvertiserOrderStatus / PublisherNeedsAction). Admin
 * open work is paid Managed-by-SLB rows that are not completed/cancelled,
 * plus open disputes. Type-based heuristics are only a fallback when no order
 * row can be loaded.
 */
class OrderNotificationReconciliation
{
    /** @var list<string> */
    public const STALEABLE_TYPES = [
        InAppNotificationService::TYPE_ORDER_CREATED,
        InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
        InAppNotificationService::TYPE_MODIFICATION_REQUESTED,
        InAppNotificationService::TYPE_CONTENT_REVISION_REQUESTED,
        InAppNotificationService::TYPE_CONTENT_REVISION_FULFILLED,
        InAppNotificationService::TYPE_PAYMENT_FAILED,
        InAppNotificationService::TYPE_ORDER_UPDATED,
    ];

    /**
     * Order IDs that currently need action for this user + marketplace role.
     *
     * @param  list<int>  $orderIds
     * @return array<int, true>
     */
    public function liveActionOrderIds(int $userId, string $audience, array $orderIds, ?Collection $ordersById = null): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === [] || $userId < 1) {
            return [];
        }

        $audience = strtolower(trim($audience));
        $needed = [];

        try {
            if (in_array($audience, [InAppNotification::AUDIENCE_ADVERTISER, ''], true)
                || $audience === InAppNotification::AUDIENCE_ALL) {
                $needed += $this->advertiserActionIds($userId, $orderIds, $ordersById);
            }
            if ($audience === InAppNotification::AUDIENCE_PUBLISHER
                || $audience === InAppNotification::AUDIENCE_ALL) {
                $needed += $this->publisherActionIds($userId, $orderIds);
            }
            if (in_array($audience, [InAppNotification::AUDIENCE_ADMIN, 'marketing'], true)
                || $audience === InAppNotification::AUDIENCE_ALL) {
                $needed += $this->adminActionIds($orderIds);
            }
        } catch (\Throwable $e) {
            Log::warning('Order notification live-action lookup failed', [
                'user_id' => $userId,
                'audience' => $audience,
                'error' => $e->getMessage(),
            ]);
        }

        return $needed;
    }

    /**
     * @param  array<int, true>  $liveActionIds
     */
    public function notificationNeedsAction(
        InAppNotification $notification,
        ?Order $order,
        array $liveActionIds
    ): bool {
        if ($order && (int) $order->id > 0) {
            if (isset($liveActionIds[(int) $order->id])) {
                return true;
            }

            $audience = strtolower((string) ($notification->audience ?: ''));
            if ($audience === InAppNotification::AUDIENCE_ADMIN || $audience === 'marketing') {
                // Stalled / override bells stay actionable while the order is still open work.
                if ($notification->type === InAppNotificationService::TYPE_ORDER_UPDATED
                    && $this->isOpenPaidWork($order)) {
                    return true;
                }
            }

            return false;
        }

        return $this->typeHeuristic(
            (string) $notification->type,
            $notification->action_label,
            $notification->priority
        );
    }

    public function typeHeuristic(string $type, ?string $actionLabel = null, ?string $priority = null): bool
    {
        if (in_array($type, [
            InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
            InAppNotificationService::TYPE_ORDER_CREATED,
            InAppNotificationService::TYPE_MODIFICATION_REQUESTED,
            InAppNotificationService::TYPE_CONTENT_REVISION_REQUESTED,
            InAppNotificationService::TYPE_CONTENT_REVISION_FULFILLED,
            InAppNotificationService::TYPE_CONTENT_NEEDS_CHANGES,
            InAppNotificationService::TYPE_PAYMENT_FAILED,
            InAppNotificationService::TYPE_ORDER_REJECTED,
        ], true)) {
            return true;
        }

        if ($type === InAppNotificationService::TYPE_ORDER_UPDATED) {
            $label = strtolower((string) $actionLabel);
            foreach (['review', 'accept', 'submit', 'open task', 'open tasks', 'send revised'] as $needle) {
                if ($needle !== '' && str_contains($label, $needle)) {
                    return true;
                }
            }
            if (in_array($priority, [InAppNotification::PRIORITY_HIGH, InAppNotification::PRIORITY_URGENT], true)) {
                return true;
            }
        }

        return false;
    }

    public function isStaleActionNotification(InAppNotification $notification, ?Order $order): bool
    {
        if (! in_array((string) $notification->type, self::STALEABLE_TYPES, true)) {
            return false;
        }

        if (! $order) {
            // Missing related_id is not a leftover order task (e.g. admin
            // "new order" ops bells with no order row). Deleted orders are.
            return (int) $notification->related_id > 0;
        }

        $type = (string) $notification->type;
        $audience = strtolower((string) ($notification->audience ?: ''));
        $meta = is_array($notification->meta) ? $notification->meta : [];
        $track = is_string($meta['track'] ?? null) ? (string) $meta['track'] : null;
        $terminal = $this->isTerminal($order);
        $status = (string) $order->status;
        $payment = (string) $order->payment_status;

        if ($type === InAppNotificationService::TYPE_PAYMENT_FAILED) {
            return $payment !== 'failed';
        }

        if ($type === InAppNotificationService::TYPE_ORDER_CREATED) {
            if ($terminal || $payment !== 'paid' || $status !== 'pending') {
                return true;
            }
            if (method_exists($order, 'isAwaitingScheduledRelease') && $order->isAwaitingScheduledRelease()) {
                return true;
            }
            if ($this->orderIsFullyManaged($order)) {
                return true;
            }

            return false;
        }

        if ($type === InAppNotificationService::TYPE_GUEST_POST_PUBLISHED) {
            return $terminal;
        }

        if ($type === InAppNotificationService::TYPE_MODIFICATION_REQUESTED) {
            return $terminal || ! $this->anyItemFlag($order, 'modification_requested');
        }

        if ($type === InAppNotificationService::TYPE_CONTENT_REVISION_REQUESTED) {
            return $terminal || ! $this->anyItemFlag($order, 'content_revision_requested');
        }

        if ($type === InAppNotificationService::TYPE_CONTENT_REVISION_FULFILLED) {
            if ($terminal || in_array($status, ['review', 'pending'], true)) {
                return true;
            }

            return $this->anyItemHasLiveUrl($order);
        }

        if ($type === InAppNotificationService::TYPE_ORDER_UPDATED) {
            if ($track === 'accept') {
                return $terminal || $payment !== 'paid' || $status !== 'pending'
                    || (method_exists($order, 'isAwaitingScheduledRelease') && $order->isAwaitingScheduledRelease());
            }
            if ($track === 'publish') {
                return $terminal || $status !== 'processing' || $this->anyItemHasLiveUrl($order);
            }
            if ($track === 'review_nudge') {
                return $terminal || $status !== 'review' || ! $this->anyItemHasLiveUrl($order)
                    || $this->orderIsFullyManaged($order);
            }
            if ($audience === InAppNotification::AUDIENCE_ADMIN || $audience === 'marketing') {
                return $terminal && ! $this->orderHasOpenDispute($order);
            }

            return $terminal;
        }

        return false;
    }

    public function isTerminal(Order $order): bool
    {
        $status = (string) $order->status;
        $payment = (string) $order->payment_status;

        return $status === 'cancelled' || $status === 'completed' || $payment === 'refunded';
    }

    /**
     * Paid managed placements that still need staff work (not completed/cancelled).
     *
     * @return Builder<Order>
     */
    public static function adminOpenWorkQuery()
    {
        $query = Order::query()
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['cancelled', 'completed']);

        if (ManagedFulfilmentService::hasFulfilmentColumn()) {
            $query->whereHas('items', fn ($iq) => $iq->managedBySlb());
        } else {
            $query->whereRaw('0 = 1');
        }

        return $query;
    }

    public static function adminOpenWorkCount(): int
    {
        try {
            if (! AdvertiserOrderStatus::itemsTableAvailable()) {
                return 0;
            }

            return static::adminOpenWorkQuery()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  list<int>  $orderIds
     * @param  Collection<int, Order>|null  $ordersById
     * @return array<int, true>
     */
    protected function advertiserActionIds(int $userId, array $orderIds, ?Collection $ordersById): array
    {
        $needed = [];
        try {
            foreach (AdvertiserOrderStatus::needsActionQuery($userId)->whereIn('orders.id', $orderIds)->pluck('orders.id') as $id) {
                $needed[(int) $id] = true;
            }
        } catch (\Throwable) {
            // leftovers
        }

        $orders = $ordersById ?? collect();
        foreach ($orderIds as $id) {
            $order = $orders->get($id);
            if ($order && (string) $order->payment_status === 'failed') {
                $needed[(int) $id] = true;
            }
        }

        return $needed;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, true>
     */
    protected function publisherActionIds(int $publisherId, array $orderIds): array
    {
        $needed = [];
        try {
            foreach (PublisherNeedsAction::needsYouQuery($publisherId)
                ->whereIn('order_items.order_id', $orderIds)
                ->pluck('order_items.order_id') as $id) {
                $needed[(int) $id] = true;
            }
        } catch (\Throwable) {
            // leftovers
        }

        return $needed;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, true>
     */
    protected function adminActionIds(array $orderIds): array
    {
        $needed = [];
        try {
            foreach (static::adminOpenWorkQuery()->whereIn('orders.id', $orderIds)->pluck('orders.id') as $id) {
                $needed[(int) $id] = true;
            }
        } catch (\Throwable) {
            // leftovers
        }

        if (OrderItemDispute::tableAvailable()) {
            try {
                foreach (OrderItemDispute::query()
                    ->where('status', OrderItemDispute::STATUS_OPEN)
                    ->whereIn('order_id', $orderIds)
                    ->pluck('order_id') as $id) {
                    $needed[(int) $id] = true;
                }
            } catch (\Throwable) {
                // leftovers
            }
        }

        return $needed;
    }

    protected function isOpenPaidWork(Order $order): bool
    {
        return (string) $order->payment_status === 'paid'
            && ! in_array((string) $order->status, ['cancelled', 'completed'], true);
    }

    protected function orderIsFullyManaged(Order $order): bool
    {
        try {
            if (! Schema::hasColumn('order_items', 'fulfilment_source')) {
                return false;
            }
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
            if ($items->isEmpty()) {
                return false;
            }

            return $items->every(fn ($item) => $item instanceof OrderItem && $item->isManagedBySlb());
        } catch (\Throwable) {
            return false;
        }
    }

    protected function anyItemFlag(Order $order, string $column): bool
    {
        try {
            if (! Schema::hasColumn('order_items', $column)) {
                return false;
            }
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

            return $items->contains(function ($item) use ($column) {
                return $item && (string) ($item->{$column} ?? 'no') === 'yes';
            });
        } catch (\Throwable) {
            return false;
        }
    }

    protected function anyItemHasLiveUrl(Order $order): bool
    {
        try {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

            return $items->contains(fn ($item) => $item && filled($item->live_url ?? null));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function orderHasOpenDispute(Order $order): bool
    {
        if (! OrderItemDispute::tableAvailable()) {
            return false;
        }
        try {
            return OrderItemDispute::query()
                ->where('order_id', $order->id)
                ->where('status', OrderItemDispute::STATUS_OPEN)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
