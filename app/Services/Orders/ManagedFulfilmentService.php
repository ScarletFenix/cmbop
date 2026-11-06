<?php

namespace App\Services\Orders;

use App\Mail\ManagedOrderCompletedMail;
use App\Mail\ManagedOrderConfirmedMail;
use App\Mail\ManagedOrderLiveUrlMail;
use App\Models\InAppNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\CheckoutSchemaService;
use App\Services\InAppNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Email-managed “Managed by SLB” fulfilment: admin-only ownership, publisher isolation,
 * and complete-without-publisher-wallet-credit.
 */
class ManagedFulfilmentService
{
    public const SOURCE_PUBLISHER = 'publisher';

    public const SOURCE_MANAGED_BY_SLB = 'managed_by_slb';

    public const STATUS_AWAITING_CONTENT = 'awaiting_content';

    public const STATUS_WITH_PUBLISHER = 'with_publisher';

    public const STATUS_LIVE_URL_RECEIVED = 'live_url_received';

    public const STATUS_COMPLETED = 'completed';

    /** @return list<string> */
    public static function internalStatuses(): array
    {
        return [
            self::STATUS_AWAITING_CONTENT,
            self::STATUS_WITH_PUBLISHER,
            self::STATUS_LIVE_URL_RECEIVED,
            self::STATUS_COMPLETED,
        ];
    }

    public static function hasFulfilmentColumn(): bool
    {
        try {
            return Schema::hasTable('order_items')
                && Schema::hasColumn('order_items', 'fulfilment_source');
        } catch (\Throwable) {
            return false;
        }
    }

    public function isManaged(OrderItem $item): bool
    {
        if (! self::hasFulfilmentColumn()) {
            return false;
        }

        return (string) ($item->fulfilment_source ?? self::SOURCE_PUBLISHER) === self::SOURCE_MANAGED_BY_SLB;
    }

    public function orderHasManagedLine(Order $order): bool
    {
        if (! self::hasFulfilmentColumn()) {
            return false;
        }

        try {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        } catch (\Throwable) {
            return false;
        }

        return $items->contains(fn (OrderItem $item) => $this->isManaged($item));
    }

    public function orderIsFullyManaged(Order $order): bool
    {
        if (! self::hasFulfilmentColumn()) {
            return false;
        }

        try {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        } catch (\Throwable) {
            return false;
        }

        if ($items->isEmpty()) {
            return false;
        }

        return $items->every(fn (OrderItem $item) => $this->isManaged($item));
    }

    /**
     * Hide managed lines from publisher Tasks / needs-you / chat access.
     *
     * @param  Builder<OrderItem>  $query
     * @return Builder<OrderItem>
     */
    public function excludeManagedFromPublisherQuery(Builder $query): Builder
    {
        if (! self::hasFulfilmentColumn()) {
            return $query;
        }

        return $query->where(function (Builder $q) {
            $q->whereNull('fulfilment_source')
                ->orWhere('fulfilment_source', '!=', self::SOURCE_MANAGED_BY_SLB);
        });
    }

    /**
     * Admin + marketing staff who can own a managed desk row.
     *
     * @return Collection<int, User>
     */
    public function staffAssignees(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['admin', 'marketing']))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function validatedStaffId(?int $assigneeId): ?int
    {
        if (! $assigneeId) {
            return null;
        }

        $ok = $this->staffAssignees()->contains(fn (User $u) => (int) $u->id === (int) $assigneeId);

        return $ok ? (int) $assigneeId : null;
    }

    /**
     * @return Builder<OrderItem>
     */
    public function deskQuery(?string $internalStatus = null, ?int $assigneeId = null): Builder
    {
        app(CheckoutSchemaService::class)->ensureCheckoutTables();

        $query = OrderItem::query()
            ->with(['order.user', 'site', 'managedAssignee'])
            ->where('fulfilment_source', self::SOURCE_MANAGED_BY_SLB)
            ->whereHas('order', function ($q) {
                $q->where('payment_status', 'paid');
            })
            ->orderByDesc('updated_at');

        if ($internalStatus !== null && $internalStatus !== '') {
            $query->where('managed_internal_status', $internalStatus);
        }
        if ($assigneeId) {
            $query->where('managed_assignee_id', $assigneeId);
        }

        return $query;
    }

    public function markManaged(OrderItem $item, User $actor, ?int $assigneeId = null, ?string $notes = null, bool $notifyAdvertiser = true): OrderItem
    {
        if (! self::hasFulfilmentColumn()) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Managed fulfilment is not available until the database columns are applied.',
            ]);
        }

        $order = $item->order ?? Order::find($item->order_id);
        if (! $order || $order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'fulfilment' => 'Only paid orders can be marked Managed by SLB.',
            ]);
        }
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'fulfilment' => 'Cancelled orders cannot be marked Managed by SLB.',
            ]);
        }
        if ($order->status === 'completed' || $item->isPayoutComplete()) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Completed placements cannot be switched to Managed by SLB.',
            ]);
        }

        $schema = app(CheckoutSchemaService::class);
        $payload = $schema->filterExistingColumns('order_items', [
            'fulfilment_source' => self::SOURCE_MANAGED_BY_SLB,
            'managed_assignee_id' => $this->validatedStaffId($assigneeId),
            'managed_internal_status' => $item->managed_internal_status ?: self::STATUS_AWAITING_CONTENT,
            'managed_notes' => $notes !== null ? $notes : $item->managed_notes,
            'managed_marked_at' => $item->managed_marked_at ?? now(),
        ]);

        $item->forceFill($payload)->save();

        ActivityLogger::tryLog(
            'order.managed_by_slb',
            'Placement marked Managed by SLB',
            $item,
            [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
            ],
            null,
            $actor
        );

        if ($notifyAdvertiser) {
            $this->notifyAdvertiserManagedConfirmed($order->fresh('user'));
        }

        return $item->fresh(['order.user', 'site', 'managedAssignee']);
    }

    /**
     * @param  array{assignee?: bool, status?: bool, notes?: bool}  $touch
     */
    public function updateDesk(
        OrderItem $item,
        User $actor,
        ?int $assigneeId = null,
        ?string $internalStatus = null,
        ?string $notes = null,
        array $touch = ['assignee' => true, 'status' => true, 'notes' => true],
    ): OrderItem {
        if (! $this->isManaged($item)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'This placement is not Managed by SLB.',
            ]);
        }

        $schema = app(CheckoutSchemaService::class);
        $payload = [];
        if (! empty($touch['assignee'])) {
            $payload['managed_assignee_id'] = $this->validatedStaffId($assigneeId);
        }
        if (! empty($touch['status']) && $internalStatus !== null && $internalStatus !== '') {
            if (! in_array($internalStatus, self::internalStatuses(), true)) {
                throw ValidationException::withMessages([
                    'managed_internal_status' => 'Invalid internal status.',
                ]);
            }
            $payload['managed_internal_status'] = $internalStatus;
        }
        if (! empty($touch['notes']) && $notes !== null) {
            $payload['managed_notes'] = $notes;
        }

        $filtered = $schema->filterExistingColumns('order_items', $payload);
        if ($filtered !== []) {
            $item->forceFill($filtered)->save();
        }

        return $item->fresh(['order.user', 'site', 'managedAssignee']);
    }

    public function attachLiveUrl(OrderItem $item, string $liveUrl, User $actor, bool $notify = true): OrderItem
    {
        if (! $this->isManaged($item)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'This placement is not Managed by SLB.',
            ]);
        }

        $order = $item->order ?? Order::findOrFail($item->order_id);
        if ($order->status === 'completed') {
            throw ValidationException::withMessages([
                'live_url' => 'This order is already completed.',
            ]);
        }
        if ($order->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'live_url' => 'Payment must be complete before attaching a live URL.',
            ]);
        }

        $url = $this->normalizeLiveUrl($liveUrl);
        $schema = app(CheckoutSchemaService::class);
        $item->forceFill($schema->filterExistingColumns('order_items', [
            'live_url' => $url,
            'live_url_submitted_at' => now(),
            'managed_internal_status' => self::STATUS_LIVE_URL_RECEIVED,
            'modification_requested' => 'no',
        ]))->save();

        if (in_array($order->status, ['pending', 'processing', 'review'], true)) {
            $order->update($schema->filterExistingColumns('orders', [
                'status' => 'review',
            ]));
        }

        if ($notify) {
            $this->notifyAdvertiserLiveUrl($order->fresh(['user', 'items']), $item->fresh());
        }

        return $item->fresh(['order.user', 'site', 'managedAssignee']);
    }

    /**
     * Complete a managed placement without crediting the site publisher wallet.
     */
    public function complete(OrderItem $item, User $actor, ?string $liveUrl = null): Order
    {
        if (! $this->isManaged($item)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'This placement is not Managed by SLB.',
            ]);
        }

        $schema = app(CheckoutSchemaService::class);
        $schema->ensureCheckoutTables();

        return DB::transaction(function () use ($item, $actor, $liveUrl, $schema) {
            $order = Order::where('id', $item->order_id)->lockForUpdate()->firstOrFail();
            $lockedItem = OrderItem::where('id', $item->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'completed') {
                throw ValidationException::withMessages([
                    'fulfilment' => 'Order is already completed.',
                ]);
            }
            if ($order->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'fulfilment' => 'Payment must be complete before finishing a managed order.',
                ]);
            }
            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                throw ValidationException::withMessages([
                    'fulfilment' => 'This order cannot be completed.',
                ]);
            }

            if (filled($liveUrl)) {
                $lockedItem->forceFill($schema->filterExistingColumns('order_items', [
                    'live_url' => $this->normalizeLiveUrl($liveUrl),
                    'live_url_submitted_at' => $lockedItem->live_url_submitted_at ?? now(),
                ]))->save();
            }

            if (! filled($lockedItem->fresh()->live_url)) {
                throw ValidationException::withMessages([
                    'live_url' => 'Attach a live URL before completing this managed order.',
                ]);
            }

            $order->update($schema->filterExistingColumns('orders', [
                'status' => 'completed',
                'completed_at' => now(),
            ]));

            // Mark the line complete so isPayoutComplete() is true, but never Wallet::credit the publisher.
            $lockedItem->forceFill($schema->filterExistingColumns('order_items', [
                'completed_at' => $lockedItem->completed_at ?? now(),
                'publisher_status' => 'completed',
                'managed_internal_status' => self::STATUS_COMPLETED,
                'managed_completed_by' => $actor->id,
                'modification_requested' => 'no',
            ]))->save();

            // Sibling managed lines on the same order: complete them only when they already have a URL.
            foreach ($order->items()->lockForUpdate()->get() as $sibling) {
                if ((int) $sibling->id === (int) $lockedItem->id) {
                    continue;
                }
                if (! $this->isManaged($sibling)) {
                    continue;
                }
                if (! filled($sibling->live_url)) {
                    continue;
                }
                if ($sibling->isPayoutComplete()) {
                    continue;
                }
                $sibling->forceFill($schema->filterExistingColumns('order_items', [
                    'completed_at' => $sibling->completed_at ?? now(),
                    'publisher_status' => 'completed',
                    'managed_internal_status' => self::STATUS_COMPLETED,
                    'managed_completed_by' => $actor->id,
                ]))->save();
            }

            $advertiserRoleId = Wallet::advertiserRoleId();
            if ($advertiserRoleId) {
                $advertiserWallet = Wallet::lockOrCreateForRole((int) $order->user_id, (int) $advertiserRoleId);
                app(OrderRefundService::class)->consumeReservedForSettledOrder($order->fresh('items'), $advertiserWallet);
            }

            $fresh = $order->fresh(['user', 'items']);
            $this->notifyAdvertiserCompleted($fresh, $lockedItem->fresh());

            return $fresh;
        });
    }

    /**
     * Staff who should receive managed-order chat (admins; assignee first when set).
     *
     * @return list<User>
     */
    public function chatStaffReceivers(Order $order): array
    {
        $assigneeIds = [];
        try {
            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
            foreach ($items as $item) {
                if ($this->isManaged($item) && $item->managed_assignee_id) {
                    $assigneeIds[(int) $item->managed_assignee_id] = true;
                }
            }
        } catch (\Throwable) {
            // fall through to all admins
        }

        $users = [];
        if ($assigneeIds !== []) {
            foreach (User::query()->whereIn('id', array_keys($assigneeIds))->get() as $user) {
                $users[$user->id] = $user;
            }
        }

        $admins = User::query()
            ->whereHas('roles', fn ($r) => $r->where('name', 'admin'))
            ->get();
        foreach ($admins as $admin) {
            $users[$admin->id] = $admin;
        }

        return array_values($users);
    }

    public function advertiserConfirmedCopy(): string
    {
        return 'Your order is confirmed. Our team is managing this placement for you. You’ll get the live URL here when it’s published.';
    }

    public function advertiserLiveUrlCopy(): string
    {
        return 'Your article is live. Open it from My Orders. If you need a correction, reply in order chat — our team will handle it.';
    }

    private function normalizeLiveUrl(string $liveUrl): string
    {
        $url = trim($liveUrl);
        if ($url === '') {
            throw ValidationException::withMessages([
                'live_url' => 'Enter a live URL.',
            ]);
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'live_url' => 'Enter a valid live URL.',
            ]);
        }

        return $url;
    }

    private function notifyAdvertiserManagedConfirmed(Order $order): void
    {
        $user = $order->user;
        if (! $user?->email) {
            return;
        }

        try {
            Mail::to($user->email)->send(new ManagedOrderConfirmedMail($order, $this->advertiserConfirmedCopy()));
        } catch (\Throwable $e) {
            Log::warning('Managed confirm mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        try {
            app(InAppNotificationService::class)->notify(
                (int) $user->id,
                InAppNotificationService::TYPE_ORDER_UPDATED,
                'Order #'.($order->order_number ?: $order->id).' · Managed by SLB',
                $this->advertiserConfirmedCopy(),
                [
                    'category' => InAppNotificationService::CATEGORY_ORDERS,
                    'icon' => 'badge-check',
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                    'action_label' => 'Open order',
                    'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Managed confirm bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyAdvertiserLiveUrl(Order $order, OrderItem $item): void
    {
        $user = $order->user;
        if (! $user?->email) {
            return;
        }

        try {
            Mail::to($user->email)->send(new ManagedOrderLiveUrlMail($order, $item, $this->advertiserLiveUrlCopy()));
        } catch (\Throwable $e) {
            Log::warning('Managed live URL mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        try {
            app(InAppNotificationService::class)->notify(
                (int) $user->id,
                InAppNotificationService::TYPE_GUEST_POST_PUBLISHED,
                'Your article is live · #'.($order->order_number ?: $order->id),
                $this->advertiserLiveUrlCopy(),
                [
                    'category' => InAppNotificationService::CATEGORY_ORDERS,
                    'icon' => 'link',
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                    'action_label' => 'Open My Orders',
                    'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Managed live URL bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyAdvertiserCompleted(Order $order, OrderItem $item): void
    {
        $user = $order->user;
        if (! $user) {
            return;
        }

        $body = 'Completed · Managed by SLB. Your live URL is ready on My Orders.';
        if ($user->email) {
            try {
                Mail::to($user->email)->send(new ManagedOrderCompletedMail($order, $item, $body));
            } catch (\Throwable $e) {
                Log::warning('Managed complete mail failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            app(InAppNotificationService::class)->notify(
                (int) $user->id,
                InAppNotificationService::TYPE_ORDER_COMPLETED,
                'Order #'.($order->order_number ?: $order->id).' completed · Managed by SLB',
                $body,
                [
                    'category' => InAppNotificationService::CATEGORY_ORDERS,
                    'icon' => 'badge-check',
                    'related' => $order,
                    'audience' => InAppNotification::AUDIENCE_ADVERTISER,
                    'action_label' => 'Open My Orders',
                    'action_url' => route('advertiser.orders', ['focus' => 'order', 'order' => $order->id], false),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Managed complete bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        try {
            app(InAppNotificationService::class)->recordOrderActivity(
                $order,
                'order.managed_completed',
                'Completed · Managed by SLB',
                'Staff finished this placement without a publisher wallet payout.',
                ['icon' => 'badge-check', 'badge_color' => 'success', 'item_id' => $item->id]
            );
        } catch (\Throwable $e) {
            // Activity is best-effort.
        }
    }
}
