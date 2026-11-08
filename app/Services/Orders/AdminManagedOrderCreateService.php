<?php

namespace App\Services\Orders;

use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\ActivityLogger;
use App\Services\Advertiser\LibraryArticlePlacement;
use App\Services\CartPricingService;
use App\Services\CheckoutSchemaService;
use App\Services\Marketplace\ArticleWritingService;
use App\Services\Marketplace\CheckoutAddOnService;
use App\Services\Marketplace\WritingFeeService;
use App\Services\Wallet\WalletLedgerService;
use App\Support\BulkDiscount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin creates paid Managed-by-SLB order(s) on behalf of an advertiser (wallet only).
 */
class AdminManagedOrderCreateService
{
    public const MAX_SITES = 20;

    public function __construct(
        private CartPricingService $pricing,
        private CheckoutAddOnService $addons,
        private CheckoutSchemaService $schema,
        private WalletLedgerService $ledger,
        private ManagedFulfilmentService $managed,
        private OrderPackService $packs,
        private LibraryArticlePlacement $libraryPlacement,
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
     * How many anchor/target pairs this listing allows on a managed brief.
     * Catalog dofollow rows expose one primary slot; admin briefs may include up to 3.
     */
    public function maxLinkPairsForSite(Site $site): int
    {
        return strtolower((string) ($site->link_type ?? '')) === 'nofollow' ? 1 : 3;
    }

    /**
     * @return list<array{value: string, label: string, data?: array<string, mixed>}>
     */
    public function searchAdvertisers(?string $q, ?int $selectedId = null, int $limit = 20): array
    {
        $query = User::query()
            ->whereHas('roles', fn ($r) => $r->where('name', 'advertiser'))
            ->orderBy('name');

        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like, $q) {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
                if (ctype_digit($q)) {
                    $inner->orWhere('id', (int) $q);
                }
            })->limit($limit);
        } else {
            $query->orderByDesc('id')->limit(min(8, $limit));
        }

        $rows = $query->get(['id', 'name', 'email']);
        if ($selectedId && ! $rows->contains(fn (User $u) => (int) $u->id === $selectedId)) {
            $selected = User::query()
                ->whereKey($selectedId)
                ->whereHas('roles', fn ($r) => $r->where('name', 'advertiser'))
                ->first(['id', 'name', 'email']);
            if ($selected) {
                $rows = $rows->prepend($selected);
            }
        }

        $roleId = Wallet::advertiserRoleId();

        return $rows->map(function (User $user) use ($roleId) {
            $balance = 0.0;
            if ($roleId) {
                try {
                    $wallet = Wallet::lockOrCreateForRole((int) $user->id, (int) $roleId);
                    $balance = round((float) $wallet->balance, 2);
                } catch (\Throwable) {
                    $balance = 0.0;
                }
            }

            return [
                'value' => (string) $user->id,
                'label' => $user->name.' · '.$user->email,
                'data' => [
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'balance' => $balance,
                    'balance_label' => '€'.number_format($balance, 2),
                ],
            ];
        })->values()->all();
    }

    /**
     * @return list<array{value: string, label: string, data?: array<string, mixed>}>
     */
    public function searchSites(?string $q, ?int $selectedId = null, int $limit = 20): array
    {
        $query = Site::query()->where('active', true)->orderBy('site_name');

        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like, $q) {
                $inner->where('site_name', 'like', $like)
                    ->orWhere('site_url', 'like', $like)
                    ->orWhere('domain', 'like', $like);
                if (ctype_digit($q)) {
                    $inner->orWhere('id', (int) $q);
                }
            })->limit($limit);
        } else {
            $query->orderByDesc('id')->limit(min(8, $limit));
        }

        $rows = $query->get();
        if ($selectedId && ! $rows->contains(fn (Site $s) => (int) $s->id === $selectedId)) {
            $selected = Site::query()->whereKey($selectedId)->where('active', true)->first();
            if ($selected) {
                $rows = $rows->prepend($selected);
            }
        }

        return $rows->map(function (Site $site) {
            $details = $this->siteDetailsPayload($site);

            return [
                'value' => (string) $site->id,
                'label' => ($site->site_name ?: $site->domain).' · '.($site->domain ?: $site->site_url),
                'data' => $details,
            ];
        })->values()->all();
    }

    /**
     * Library articles for the chosen advertiser.
     *
     * @return list<array{value: string, label: string, data?: array<string, mixed>}>
     */
    public function searchLibraryArticles(int $advertiserId, ?string $q = null, ?int $selectedId = null, int $limit = 20): array
    {
        if ($advertiserId <= 0 || ! Schema::hasTable('content_submissions')) {
            return [];
        }

        $query = ContentSubmission::query()
            ->where('user_id', $advertiserId)
            ->orderByDesc('id');

        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like, $q) {
                $inner->where('original_filename', 'like', $like)
                    ->orWhere('anchor_text', 'like', $like)
                    ->orWhere('target_url', 'like', $like);
                if (ctype_digit($q)) {
                    $inner->orWhere('id', (int) $q);
                }
            });
        }

        $rows = $query->limit($limit * 3)->get()->filter(fn (ContentSubmission $s) => $s->canOrderFromLibrary())->take($limit)->values();

        if ($selectedId) {
            $selected = ContentSubmission::query()->whereKey($selectedId)->where('user_id', $advertiserId)->first();
            if ($selected && $selected->canOrderFromLibrary()
                && ! $rows->contains(fn (ContentSubmission $s) => (int) $s->id === $selectedId)) {
                $rows = $rows->prepend($selected);
            }
        }

        return $rows->map(function (ContentSubmission $submission) {
            $label = $submission->original_filename
                ?: ($submission->anchor_text ?: ('Article #'.$submission->id));

            return [
                'value' => (string) $submission->id,
                'label' => $label,
                'data' => [
                    'anchor' => (string) ($submission->anchor_text ?? ''),
                    'target_url' => (string) ($submission->target_url ?? ''),
                ],
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function siteDetailsPayload(Site $site, ?User $advertiser = null): array
    {
        $unit = null;
        try {
            $expanded = $this->pricing->expandCart([
                ['id' => $site->id, 'name' => $site->site_name, 'quantity' => 1],
            ], $advertiser?->id);
            $unit = isset($expanded[0]['price']) ? round((float) $expanded[0]['price'], 2) : null;
        } catch (\Throwable) {
            $unit = null;
        }

        $turnaround = null;
        try {
            $hours = $site->turnaroundHours();
            $turnaround = $hours ? $hours.'h' : (string) ($site->publication_time ?: $site->turnaround_time ?: '');
        } catch (\Throwable) {
            $turnaround = (string) ($site->publication_time ?? '');
        }

        return [
            'site_id' => (int) $site->id,
            'site_name' => (string) $site->site_name,
            'domain' => (string) ($site->domain ?: $site->site_url),
            'da' => $site->da !== null ? (int) $site->da : null,
            'dr' => $site->dr !== null ? (int) $site->dr : null,
            'country' => (string) ($site->country ?? ''),
            'link_type' => (string) ($site->link_type ?? ''),
            'dofollow' => strtolower((string) ($site->link_type ?? '')) !== 'nofollow',
            'max_links' => $this->maxLinkPairsForSite($site),
            'turnaround' => $turnaround,
            'advertiser_price' => $unit,
            'advertiser_price_label' => $unit !== null ? '€'.number_format($unit, 2) : '—',
            'slb_writing_fee' => WritingFeeService::SLB_FLAT_EUR,
        ];
    }

    /**
     * Live quote for the create form / confirm step.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function quote(array $input): array
    {
        $built = $this->buildPlacementPlan($input);
        $advertiser = $built['advertiser'];
        $placements = $built['placements'];
        $total = $built['total'];

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

        $lines = [];
        foreach ($placements as $placement) {
            $line = $placement['line'];
            $lines[] = [
                'site_id' => (int) $placement['site']->id,
                'site_name' => (string) $placement['site']->site_name,
                'quantity' => (int) $placement['quantity'],
                'content_source' => $placement['content_source'],
                'unit_price' => round((float) ($line['price'] ?? 0), 2),
                'writing_fee' => round((float) ($line['writing_fee'] ?? 0), 2),
                'base_price' => round((float) ($line['price'] ?? 0) - (float) ($line['writing_fee'] ?? 0), 2),
                'line_total' => round((float) ($line['price'] ?? 0) * (int) $placement['quantity'], 2),
                'pack' => (int) $placement['quantity'] >= BulkDiscount::minQty(),
                'max_links' => $this->maxLinkPairsForSite($placement['site']),
                'links' => $placement['links'],
            ];
        }

        return [
            'advertiser_id' => (int) $advertiser->id,
            'advertiser_name' => (string) $advertiser->name,
            'advertiser_email' => (string) $advertiser->email,
            'balance' => $balance,
            'balance_label' => '€'.number_format($balance, 2),
            'total' => $total,
            'total_label' => '€'.number_format($total, 2),
            'sufficient' => $balance + 0.00001 >= $total,
            'lines' => $lines,
            'placement_count' => count($placements),
            'order_count' => array_sum(array_map(fn ($p) => (int) $p['quantity'], $placements)),
            'external_reference' => $built['external_reference'],
        ];
    }

    /**
     * Backward-compatible single-site preview used by older confirm flow.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        if (empty($input['sites']) && ! empty($input['site_id'])) {
            $input['sites'] = [[
                'site_id' => $input['site_id'],
                'quantity' => 1,
                'content_source' => $input['content_source'] ?? WritingFeeService::SOURCE_BUYER,
                'content_url' => $input['content_url'] ?? null,
                'content_submission_id' => $input['content_submission_id'] ?? null,
                'writing_brief_notes' => $input['writing_brief_notes'] ?? null,
                'links' => [[
                    'anchor' => $input['anchor_text'] ?? '',
                    'url' => $input['target_url'] ?? '',
                ]],
            ]];
        }

        $quote = $this->quote($input);
        $built = $this->buildPlacementPlan($input);
        $first = $built['placements'][0];

        return [
            'advertiser' => $built['advertiser'],
            'site' => $first['site'],
            'line' => $first['line'],
            'total' => $quote['total'],
            'balance' => $quote['balance'],
            'sufficient' => $quote['sufficient'],
            'content_source' => $first['content_source'],
            'content_url' => $first['content_url'],
            'anchor_text' => $first['links'][0]['anchor'] ?? '',
            'target_url' => $first['links'][0]['url'] ?? '',
            'quote' => $quote,
            'placements' => $built['placements'],
            'external_reference' => $built['external_reference'],
        ];
    }

    /**
     * Charge once and create one or more paid Managed-by-SLB orders.
     *
     * @param  array<string, mixed>  $input
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

        if (empty($input['sites']) && ! empty($input['site_id'])) {
            $input['sites'] = [[
                'site_id' => $input['site_id'],
                'quantity' => 1,
                'content_source' => $input['content_source'] ?? WritingFeeService::SOURCE_BUYER,
                'content_url' => $input['content_url'] ?? null,
                'content_submission_id' => $input['content_submission_id'] ?? null,
                'writing_brief_notes' => $input['writing_brief_notes'] ?? null,
                'links' => [[
                    'anchor' => $input['anchor_text'] ?? '',
                    'url' => $input['target_url'] ?? '',
                ]],
            ]];
        }

        $built = $this->buildPlacementPlan($input);
        $advertiser = $built['advertiser'];
        $placements = $built['placements'];
        $total = $built['total'];
        $externalReference = $built['external_reference'];

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'sites' => 'Nothing to charge — check site prices.',
            ]);
        }

        $assigneeId = isset($input['managed_assignee_id']) && $input['managed_assignee_id'] !== ''
            ? (int) $input['managed_assignee_id']
            : null;
        $notes = isset($input['managed_notes']) ? trim((string) $input['managed_notes']) : null;
        if ($notes === '') {
            $notes = null;
        }

        $firstOrder = DB::transaction(function () use (
            $actor,
            $advertiser,
            $placements,
            $total,
            $reference,
            $assigneeId,
            $notes,
            $externalReference,
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

            $createdOrders = collect();
            $notifyManaged = true;

            foreach ($placements as $placement) {
                $site = $placement['site'];
                $line = $placement['line'];
                $qty = (int) $placement['quantity'];
                $unitPrice = round((float) ($line['price'] ?? 0), 2);

                for ($copy = 0; $copy < $qty; $copy++) {
                    $order = Order::query()->create($this->schema->filterExistingColumns('orders', array_merge([
                        'user_id' => $advertiser->id,
                        'order_number' => Order::nextOrderNumber(),
                        'reference_code' => $reference,
                        'subtotal' => $unitPrice,
                        'tax' => 0,
                        'total_amount' => $unitPrice,
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
                        $placement,
                        $externalReference,
                    );
                    $item = OrderItem::query()->create($this->schema->filterExistingColumns('order_items', $itemPayload));

                    if (! empty($placement['submission']) && $placement['submission'] instanceof ContentSubmission && $copy === 0) {
                        $submission = $placement['submission'];
                        // One library article may only claim one placement.
                        if ($createdOrders->isEmpty() || (int) $submission->order_item_id === 0) {
                            $submission->forceFill([
                                'order_id' => $order->id,
                                'order_item_id' => $item->id,
                            ])->save();
                        }
                    }

                    try {
                        app(ArticleWritingService::class)->initializePaidItem($item, $site);
                    } catch (\Throwable $e) {
                        Log::warning('Admin managed create: writing init skipped', [
                            'order_item_id' => $item->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $this->managed->markManaged(
                        $item->fresh(),
                        $actor,
                        $assigneeId,
                        $notes,
                        $notifyManaged,
                    );
                    $notifyManaged = false;

                    if ($externalReference !== null && Schema::hasColumn('order_items', 'managed_external_reference')) {
                        $item->forceFill(['managed_external_reference' => $externalReference])->save();
                    }

                    $createdOrders->push($order->fresh(['items']));
                }
            }

            $orderIds = $createdOrders->pluck('id')->all();
            $purchaseTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('type', WalletTransaction::TYPE_PURCHASE)
                ->where('reference', $reference)
                ->latest('id')
                ->first();
            if ($purchaseTx) {
                $purchaseTx->update([
                    'related_type' => $createdOrders->first()->getMorphClass(),
                    'related_id' => $createdOrders->first()->id,
                    'meta' => array_merge((array) $purchaseTx->meta, [
                        'order_ids' => $orderIds,
                        'order_reference' => $reference,
                        'created_by_admin_id' => $actor->id,
                        'managed_by_slb' => true,
                    ]),
                ]);
            }

            try {
                $siteQty = [];
                foreach ($placements as $placement) {
                    $sid = (int) $placement['site']->id;
                    $siteQty[$sid] = ($siteQty[$sid] ?? 0) + (int) $placement['quantity'];
                }
                $this->packs->linkCreatedOrders($createdOrders, [
                    'lines' => $createdOrders->map(function (Order $order) use ($siteQty) {
                        $item = $order->items->first();
                        $siteId = (int) ($item?->site_id ?? 0);

                        return [
                            'site_id' => $siteId,
                            'bulk_pack' => ($siteQty[$siteId] ?? 0) >= BulkDiscount::minQty(),
                        ];
                    })->all(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Admin managed create: pack link skipped', ['error' => $e->getMessage()]);
            }

            ActivityLogger::tryLog(
                'order.admin_managed_created',
                'Admin created Managed by SLB order(s) for advertiser',
                $createdOrders->first(),
                [
                    'order_ids' => $orderIds,
                    'advertiser_id' => $advertiser->id,
                    'total' => $total,
                    'reference' => $reference,
                ],
                null,
                $actor
            );

            return $createdOrders->first()->fresh(['items', 'user']);
        });

        return $firstOrder;
    }

    public function findPaidByReference(string $reference): ?Order
    {
        return Order::query()
            ->where('reference_code', $reference)
            ->where('payment_status', 'paid')
            ->with(['items', 'user'])
            ->orderBy('id')
            ->first();
    }

    /**
     * @deprecated use searchAdvertisers
     */
    public function advertiserOptions(?string $search = null, int $limit = 50): Collection
    {
        $ids = collect($this->searchAdvertisers($search, null, $limit))->pluck('value')->map(fn ($v) => (int) $v);

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * @deprecated use searchSites
     */
    public function siteOptions(?string $search = null, int $limit = 50): Collection
    {
        $ids = collect($this->searchSites($search, null, $limit))->pluck('value')->map(fn ($v) => (int) $v);

        return Site::query()->whereIn('id', $ids)->orderBy('site_name')->get();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   advertiser: User,
     *   placements: list<array<string, mixed>>,
     *   total: float,
     *   external_reference: ?string
     * }
     */
    private function buildPlacementPlan(array $input): array
    {
        $advertiserId = (int) ($input['advertiser_id'] ?? 0);
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

        $sitesInput = $input['sites'] ?? null;
        if (! is_array($sitesInput) || $sitesInput === []) {
            throw ValidationException::withMessages([
                'sites' => 'Add at least one site.',
            ]);
        }
        if (count($sitesInput) > self::MAX_SITES) {
            throw ValidationException::withMessages([
                'sites' => 'Too many sites in one managed order (max '.self::MAX_SITES.').',
            ]);
        }

        $externalReference = trim((string) ($input['managed_external_reference'] ?? $input['external_reference'] ?? ''));
        $externalReference = $externalReference !== '' ? mb_substr($externalReference, 0, 500) : null;

        $placements = [];
        $total = 0.0;
        $seenLibrary = [];

        foreach (array_values($sitesInput) as $index => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $siteId = (int) ($raw['site_id'] ?? 0);
            if ($siteId <= 0) {
                throw ValidationException::withMessages([
                    "sites.$index.site_id" => 'Choose a site.',
                ]);
            }
            $site = Site::query()->whereKey($siteId)->where('active', true)->first();
            if (! $site) {
                throw ValidationException::withMessages([
                    "sites.$index.site_id" => 'That site was not found or is not active.',
                ]);
            }
            if ((int) $site->publisher_id === (int) $advertiser->id) {
                throw ValidationException::withMessages([
                    "sites.$index.site_id" => 'An advertiser cannot be ordered onto their own publisher site.',
                ]);
            }

            $quantity = max(1, min(BulkDiscount::maxQty(), (int) ($raw['quantity'] ?? 1)));
            $contentSource = strtolower(trim((string) ($raw['content_source'] ?? WritingFeeService::SOURCE_BUYER)));
            if ($contentSource === 'library') {
                $contentSource = WritingFeeService::SOURCE_BUYER;
                $isLibrary = true;
            } else {
                $isLibrary = false;
            }
            if (! in_array($contentSource, [WritingFeeService::SOURCE_BUYER, WritingFeeService::SOURCE_SLB], true)) {
                throw ValidationException::withMessages([
                    "sites.$index.content_source" => 'Pick advertiser content, SLB writing, or Content Library.',
                ]);
            }

            $contentUrl = trim((string) ($raw['content_url'] ?? ''));
            $contentUrl = $contentUrl !== '' ? $contentUrl : null;
            $submissionId = (int) ($raw['content_submission_id'] ?? 0);
            $submission = null;
            $writingNotes = trim((string) ($raw['writing_brief_notes'] ?? ''));
            $writingNotes = $writingNotes !== '' ? mb_substr($writingNotes, 0, 5000) : null;

            $links = $this->normalizeLinks($raw['links'] ?? [], $site, $index);
            $anchor = $links[0]['anchor'];
            $target = $links[0]['url'];

            if ($isLibrary || $submissionId > 0) {
                $submission = ContentSubmission::query()
                    ->whereKey($submissionId)
                    ->where('user_id', $advertiser->id)
                    ->first();
                if (! $submission || ! $submission->canOrderFromLibrary()) {
                    throw ValidationException::withMessages([
                        "sites.$index.content_submission_id" => 'Choose a Content Library article that is available to order.',
                    ]);
                }
                if ($this->libraryPlacement->isLockedToAnotherSite($submission, [], $siteId)) {
                    throw ValidationException::withMessages([
                        "sites.$index.content_submission_id" => LibraryArticlePlacement::LOCK_MESSAGE,
                    ]);
                }
                if (isset($seenLibrary[$submission->id])) {
                    throw ValidationException::withMessages([
                        "sites.$index.content_submission_id" => 'Each Content Library article can only be used on one site in this order.',
                    ]);
                }
                $seenLibrary[$submission->id] = $siteId;
                $contentSource = WritingFeeService::SOURCE_BUYER;
                if ($anchor === '' && filled($submission->anchor_text)) {
                    $anchor = (string) $submission->anchor_text;
                    $links[0]['anchor'] = $anchor;
                }
                if ($target === '' && filled($submission->target_url)) {
                    $target = (string) $submission->target_url;
                    $links[0]['url'] = $target;
                }
            } elseif ($contentSource === WritingFeeService::SOURCE_BUYER) {
                if ($contentUrl === null || ! $this->isValidHttpUrl($contentUrl)) {
                    throw ValidationException::withMessages([
                        "sites.$index.content_url" => 'Provide a valid http(s) content URL, or pick Content Library / SLB writing.',
                    ]);
                }
            }

            if ($anchor === '' || ! $this->isValidHttpsUrl($target)) {
                throw ValidationException::withMessages([
                    "sites.$index.links" => 'Each site needs at least one anchor and an https:// target URL.',
                ]);
            }

            $line = $this->priceLine($advertiser, $site, $contentSource, $anchor, $target, $quantity);
            $unit = round((float) ($line['price'] ?? 0), 2);
            $total = round($total + ($unit * $quantity), 2);

            $placements[] = [
                'site' => $site,
                'quantity' => $quantity,
                'content_source' => $contentSource,
                'content_url' => $contentUrl,
                'submission' => $submission,
                'writing_brief_notes' => $writingNotes,
                'links' => $links,
                'line' => $line,
                'is_library' => $isLibrary || $submission instanceof ContentSubmission,
            ];
        }

        if ($placements === []) {
            throw ValidationException::withMessages([
                'sites' => 'Add at least one site.',
            ]);
        }

        return [
            'advertiser' => $advertiser,
            'placements' => $placements,
            'total' => $total,
            'external_reference' => $externalReference,
        ];
    }

    /**
     * @param  mixed  $rawLinks
     * @return list<array{anchor: string, url: string}>
     */
    private function normalizeLinks(mixed $rawLinks, Site $site, int $siteIndex): array
    {
        $max = $this->maxLinkPairsForSite($site);
        $list = is_array($rawLinks) ? array_values($rawLinks) : [];
        $out = [];
        foreach ($list as $i => $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $anchor = trim((string) ($pair['anchor'] ?? $pair['anchor_text'] ?? ''));
            $url = trim((string) ($pair['url'] ?? $pair['target_url'] ?? ''));
            if ($anchor === '' && $url === '') {
                continue;
            }
            if ($url !== '' && ! $this->isValidHttpsUrl($url)) {
                throw ValidationException::withMessages([
                    "sites.$siteIndex.links.$i.url" => 'Target URL must be a valid https:// link.',
                ]);
            }
            $out[] = ['anchor' => $anchor, 'url' => $url];
        }

        if (count($out) > $max) {
            throw ValidationException::withMessages([
                "sites.$siteIndex.links" => sprintf(
                    'This site allows at most %d link pair%s (dofollow allowance).',
                    $max,
                    $max === 1 ? '' : 's'
                ),
            ]);
        }

        if ($out === []) {
            $out[] = ['anchor' => '', 'url' => ''];
        }

        return $out;
    }

    private function isValidHttpsUrl(string $url): bool
    {
        return (bool) preg_match('#^https://#i', $url) && filter_var($url, FILTER_VALIDATE_URL);
    }

    private function isValidHttpUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * @return array<string, mixed>
     */
    private function priceLine(
        User $advertiser,
        Site $site,
        string $contentSource,
        string $anchor,
        string $target,
        int $quantity = 1,
    ): array {
        $expanded = $this->pricing->expandCart([
            ['id' => $site->id, 'name' => $site->site_name, 'quantity' => max(1, $quantity)],
        ], (int) $advertiser->id);

        if ($expanded === []) {
            throw ValidationException::withMessages([
                'sites' => 'Could not price '.$site->site_name.' for this advertiser.',
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
     * @param  array<string, mixed>  $placement
     * @return array<string, mixed>
     */
    private function itemPayload(
        int $orderId,
        Site $site,
        array $orderItem,
        array $placement,
        ?string $externalReference,
    ): array {
        $addons = $this->addons->orderItemColumns($orderItem);
        $links = $placement['links'] ?? [];
        $anchor = (string) ($links[0]['anchor'] ?? '');
        $target = (string) ($links[0]['url'] ?? '');
        $contentSource = (string) $placement['content_source'];
        $contentUrl = $placement['content_url'] ?? null;
        /** @var ContentSubmission|null $submission */
        $submission = $placement['submission'] ?? null;

        $link = $contentUrl
            ?: ($submission ? 'library-article' : OrderItem::PENDING_WRITTEN_LINK);

        $briefNotes = $placement['writing_brief_notes'] ?? null;
        if (count($links) > 1) {
            $extra = collect($links)->map(fn ($l, $i) => ($i + 1).'. '.$l['anchor'].' → '.$l['url'])->implode("\n");
            $briefNotes = trim(($briefNotes ? $briefNotes."\n\n" : '')."Link pairs:\n".$extra);
        }

        $payload = array_merge([
            'order_id' => $orderId,
            'site_id' => $site->id,
            'site_name' => $site->site_name,
            'site_url' => $site->site_url,
            'price' => $orderItem['price'],
            'content_link' => $link,
            'content_submission_id' => $submission?->id,
            'content_disk' => $submission?->disk,
            'content_path' => $submission?->path,
            'content_original_name' => $submission?->original_filename,
            'content_mime' => $submission?->mime,
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
            'writing_brief_notes' => $briefNotes,
            'managed_external_reference' => $externalReference,
            ...OrderItem::fxSnapshotFromPricing($orderItem),
        ], $addons);

        return $payload;
    }
}
