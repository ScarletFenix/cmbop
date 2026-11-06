<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\CartPricingService;
use App\Services\CheckoutSchemaService;
use App\Services\Marketplace\ArticleWritingService;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\WritingFeeService;
use App\Services\Wallet\WalletLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin creates a paid Managed-by-SLB order on behalf of an advertiser (wallet only).
 */
class AdminManagedOrderCreateService
{
    public function __construct(
        private CartPricingService $pricing,
        private CheckoutAddOnService $addons,
        private CheckoutSchemaService $schema,
        private WalletLedgerService $ledger,
        private ManagedFulfilmentService $managed,
    ) {}

    public function newIdempotencyKey(): string
    {
        return (string) Str::uuid();
    }

    public function referenceFromIdempotencyKey(string $key): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '');
        if ($normalized === '') {
            $normalized = strtoupper(bin2hex(random_bytes(8)));
        }

        return 'ADM-MNG-'.substr($normalized, 0, 32);
    }

    /**
     * Quote advertiser-facing price + wallet balance for the confirm step (no charge).
     *
     * @param  array{
     *   advertiser_id: int,
     *   site_id: int,
     *   anchor_text: string,
     *   target_url: string,
     *   content_source?: string,
     *   content_url?: ?string,
     * }  $input
     * @return array{
     *   advertiser: User,
     *   site: Site,
     *   line: array<string, mixed>,
     *   total: float,
     *   balance: float,
     *   sufficient: bool,
     *   content_source: string,
     *   content_url: ?string,
     *   anchor_text: string,
     *   target_url: string,
     * }
     */
    public function preview(array $input): array
    {
        [$advertiser, $site, $anchor, $target, $contentSource, $contentUrl] = $this->resolveParties($input);
        $line = $this->priceLine($advertiser, $site, $contentSource, $anchor, $target);
        $total = round((float) ($line['price'] ?? 0), 2);

        $roleId = Wallet::advertiserRoleId();
        if (! $roleId) {
            throw ValidationException::withMessages([
                'advertiser_id' => 'Advertiser wallet role is not available.',
            ]);
        }
        $wallet = Wallet::lockOrCreateForRole((int) $advertiser->id, (int) $roleId);
        $wallet->repairOrphanedWelcomeBonus();
        $wallet->reconcileInflatedBonusBalance();
        $wallet->refresh();
        $balance = round((float) $wallet->balance, 2);

        return [
            'advertiser' => $advertiser,
            'site' => $site,
            'line' => $line,
            'total' => $total,
            'balance' => $balance,
            'sufficient' => $balance + 0.00001 >= $total,
            'content_source' => $contentSource,
            'content_url' => $contentUrl,
            'anchor_text' => $anchor,
            'target_url' => $target,
        ];
    }

    /**
     * Charge the advertiser wallet once and create a paid Managed-by-SLB order.
     *
     * @param  array{
     *   advertiser_id: int,
     *   site_id: int,
     *   anchor_text: string,
     *   target_url: string,
     *   content_source?: string,
     *   content_url?: ?string,
     *   managed_assignee_id?: ?int,
     *   managed_notes?: ?string,
     *   idempotency_key: string,
     * }  $input
     */
    public function create(User $actor, array $input): Order
    {
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || strlen($idempotencyKey) < 8) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Confirm again — the charge token is missing. This protects against a double charge.',
            ]);
        }

        $reference = $this->referenceFromIdempotencyKey($idempotencyKey);
        $existing = $this->findPaidByReference($reference);
        if ($existing) {
            return $existing;
        }

        [$advertiser, $site, $anchor, $target, $contentSource, $contentUrl] = $this->resolveParties($input);
        $line = $this->priceLine($advertiser, $site, $contentSource, $anchor, $target);
        $total = round((float) ($line['price'] ?? 0), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages([
                'site_id' => 'This site has no advertiser price to charge.',
            ]);
        }

        $assigneeId = isset($input['managed_assignee_id']) && $input['managed_assignee_id'] !== ''
            ? (int) $input['managed_assignee_id']
            : null;
        $notes = isset($input['managed_notes']) ? trim((string) $input['managed_notes']) : null;
        if ($notes === '') {
            $notes = null;
        }

        $order = DB::transaction(function () use (
            $actor,
            $advertiser,
            $site,
            $line,
            $total,
            $reference,
            $anchor,
            $target,
            $contentSource,
            $contentUrl,
            $assigneeId,
            $notes,
        ) {
            $replay = $this->findPaidByReference($reference);
            if ($replay) {
                return $replay;
            }

            $roleId = Wallet::advertiserRoleId();
            if (! $roleId) {
                throw ValidationException::withMessages([
                    'advertiser_id' => 'Advertiser wallet role is not available.',
                ]);
            }

            $wallet = Wallet::lockOrCreateForRole((int) $advertiser->id, (int) $roleId);
            $wallet->repairOrphanedWelcomeBonus();
            $wallet->reconcileInflatedBonusBalance();
            $wallet->refresh();

            if ($reason = $wallet->advertiserSpendBlockedReason()) {
                throw ValidationException::withMessages([
                    'advertiser_id' => 'This advertiser cannot spend from wallet: '.$reason,
                ]);
            }

            $balance = round((float) $wallet->balance, 2);
            if ($balance < $total) {
                throw ValidationException::withMessages([
                    'advertiser_id' => sprintf(
                        'Insufficient wallet balance. Needed €%s, available €%s. Top up the advertiser wallet before creating this order.',
                        number_format($total, 2),
                        number_format($balance, 2)
                    ),
                ]);
            }

            // Already charged for this confirm token (failed after ledger / before order)? Reuse.
            $priorPurchase = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', WalletTransaction::TYPE_PURCHASE)
                ->where('reference', $reference)
                ->latest('id')
                ->first();

            if (! $priorPurchase) {
                $bonusUsed = $wallet->reserveForOrder($total, true);
                $this->ledger->recordPurchaseOnce($wallet, $total, $bonusUsed, null, $reference);
            }

            $this->schema->ensureCheckoutTables();

            $order = Order::query()->create($this->schema->filterExistingColumns('orders', array_merge([
                'user_id' => $advertiser->id,
                'order_number' => Order::nextOrderNumber(),
                'reference_code' => $reference,
                'subtotal' => $total,
                'tax' => 0,
                'total_amount' => $total,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'status' => 'pending',
                'sensitive_type' => $line['sensitive_type'] ?? null,
                'additional_price' => $line['additional_price'] ?? 0,
                'paid_at' => now(),
            ], $this->addons->orderColumns($line))));

            $itemPayload = $this->itemPayload(
                (int) $order->id,
                $site,
                $line,
                $anchor,
                $target,
                $contentSource,
                $contentUrl,
            );
            $item = OrderItem::query()->create($this->schema->filterExistingColumns('order_items', $itemPayload));

            try {
                app(ArticleWritingService::class)->initializePaidItem($item, $site);
            } catch (\Throwable $e) {
                Log::warning('Admin managed create: writing init skipped', [
                    'order_item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $purchaseTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', WalletTransaction::TYPE_PURCHASE)
                ->where('reference', $reference)
                ->latest('id')
                ->first();
            if ($purchaseTx && ! $purchaseTx->related_id) {
                $purchaseTx->update([
                    'related_type' => $order->getMorphClass(),
                    'related_id' => $order->id,
                    'meta' => array_merge((array) $purchaseTx->meta, [
                        'order_ids' => [$order->id],
                        'order_reference' => $reference,
                        'created_by_admin_id' => $actor->id,
                        'managed_by_slb' => true,
                    ]),
                ]);
            }

            $this->managed->markManaged(
                $item->fresh(),
                $actor,
                $assigneeId,
                $notes,
                true,
            );

            ActivityLogger::tryLog(
                'order.admin_managed_created',
                'Admin created Managed by SLB order for advertiser',
                $order,
                [
                    'order_id' => $order->id,
                    'advertiser_id' => $advertiser->id,
                    'site_id' => $site->id,
                    'total' => $total,
                    'reference' => $reference,
                ],
                null,
                $actor
            );

            return $order->fresh(['items', 'user']);
        });

        return $order;
    }

    public function findPaidByReference(string $reference): ?Order
    {
        return Order::query()
            ->where('reference_code', $reference)
            ->where('payment_status', 'paid')
            ->with(['items', 'user'])
            ->first();
    }

    /**
     * Advertisers for the create form picker (verified preferred, not exhaustive dump of guests).
     *
     * @return Collection<int, User>
     */
    public function advertiserOptions(?string $search = null, int $limit = 50)
    {
        $query = User::query()
            ->whereHas('roles', fn ($r) => $r->where('name', 'advertiser'))
            ->orderBy('name')
            ->limit(max(1, min(100, $limit)));

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('id', $search);
            });
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * Active catalog sites for the create form.
     *
     * @return Collection<int, Site>
     */
    public function siteOptions(?string $search = null, int $limit = 50)
    {
        $query = Site::query()
            ->where('active', true)
            ->orderBy('site_name')
            ->limit(max(1, min(100, $limit)));

        $search = trim((string) $search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('site_name', 'like', $like)
                    ->orWhere('site_url', 'like', $like)
                    ->orWhere('domain', 'like', $like)
                    ->orWhere('id', $search);
            });
        }

        return $query->get(['id', 'site_name', 'site_url', 'domain', 'price', 'publisher_id']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: User, 1: Site, 2: string, 3: string, 4: string, 5: ?string}
     */
    private function resolveParties(array $input): array
    {
        $advertiserId = (int) ($input['advertiser_id'] ?? 0);
        $siteId = (int) ($input['site_id'] ?? 0);
        $anchor = trim((string) ($input['anchor_text'] ?? ''));
        $target = trim((string) ($input['target_url'] ?? ''));
        $contentSource = strtolower(trim((string) ($input['content_source'] ?? WritingFeeService::SOURCE_BUYER)));
        $contentUrl = trim((string) ($input['content_url'] ?? ''));
        $contentUrl = $contentUrl !== '' ? $contentUrl : null;

        if ($advertiserId <= 0) {
            throw ValidationException::withMessages([
                'advertiser_id' => 'Choose an advertiser.',
            ]);
        }
        $advertiser = User::query()
            ->whereKey($advertiserId)
            ->whereHas('roles', fn ($r) => $r->where('name', 'advertiser'))
            ->first();
        if (! $advertiser) {
            throw ValidationException::withMessages([
                'advertiser_id' => 'That advertiser was not found.',
            ]);
        }

        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => 'Choose a site.',
            ]);
        }
        $site = Site::query()->whereKey($siteId)->first();
        if (! $site || ! $site->active) {
            throw ValidationException::withMessages([
                'site_id' => 'That site was not found or is not active.',
            ]);
        }
        if ((int) $site->publisher_id === (int) $advertiser->id) {
            throw ValidationException::withMessages([
                'site_id' => 'An advertiser cannot be ordered onto their own publisher site.',
            ]);
        }

        if ($anchor === '') {
            throw ValidationException::withMessages([
                'anchor_text' => 'Enter the anchor text.',
            ]);
        }
        if ($target === '' || ! preg_match('#^https://#i', $target)) {
            throw ValidationException::withMessages([
                'target_url' => 'Enter an https:// target URL.',
            ]);
        }

        if (! in_array($contentSource, [WritingFeeService::SOURCE_BUYER, WritingFeeService::SOURCE_SLB], true)) {
            throw ValidationException::withMessages([
                'content_source' => 'Content source must be buyer-provided or SLB writing.',
            ]);
        }
        if ($contentSource === WritingFeeService::SOURCE_BUYER) {
            if ($contentUrl === null || ! preg_match('#^https?://#i', $contentUrl)) {
                throw ValidationException::withMessages([
                    'content_url' => 'Provide an http(s) content URL when the advertiser supplies the article.',
                ]);
            }
        }

        return [$advertiser, $site, $anchor, $target, $contentSource, $contentUrl];
    }

    /**
     * @return array<string, mixed>
     */
    private function priceLine(User $advertiser, Site $site, string $contentSource, string $anchor, string $target): array
    {
        $expanded = $this->pricing->expandCart([
            ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
        ], (int) $advertiser->id);

        if ($expanded === []) {
            throw ValidationException::withMessages([
                'site_id' => 'Could not price this site for the advertiser.',
            ]);
        }

        $orderItem = $expanded[0];
        $cartLine = [
            'id' => $site->id,
            'content_source' => $contentSource === WritingFeeService::SOURCE_SLB
                ? ($this->addons->requestedWriter($site) ?: WritingFeeService::SOURCE_SLB)
                : WritingFeeService::SOURCE_BUYER,
            'writing_anchor_text' => $anchor,
            'writing_target_url' => $target,
        ];

        return $this->addons->decorateOrderItem($orderItem, $cartLine, false);
    }

    /**
     * @param  array<string, mixed>  $orderItem
     * @return array<string, mixed>
     */
    private function itemPayload(
        int $orderId,
        Site $site,
        array $orderItem,
        string $anchor,
        string $target,
        string $contentSource,
        ?string $contentUrl,
    ): array {
        $addons = $this->addons->orderItemColumns($orderItem);
        $link = $contentUrl
            ?: (CheckoutAddOnService::isWrittenSource($orderItem) || $contentSource === WritingFeeService::SOURCE_SLB
                ? OrderItem::PENDING_WRITTEN_LINK
                : OrderItem::PENDING_WRITTEN_LINK);

        return array_merge([
            'order_id' => $orderId,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $orderItem['price'],
            'content_link' => $link,
            'anchor_text' => $anchor,
            'target_url' => $target,
            'sensitive_type' => $orderItem['sensitive_type'] ?? null,
            'additional_price' => $orderItem['additional_price'] ?? 0,
            'homepage_days' => $orderItem['homepage_days'] ?? null,
            'homepage_price' => $orderItem['homepage_price'] ?? 0,
            'social_channels' => $orderItem['social_channels'] ?? ($site->enabledSocialChannels() ?: []),
            'publisher_price' => $orderItem['publisher_price'] ?? null,
            'platform_fee_percent' => $orderItem['platform_fee_percent'] ?? null,
            'platform_fee_amount' => $orderItem['platform_fee_amount'] ?? null,
            'modification_requested' => 'no',
            'fulfilment_source' => ManagedFulfilmentService::SOURCE_MANAGED_BY_SLB,
            ...OrderItem::fxSnapshotFromPricing($orderItem),
        ], $addons);
    }
}
