<?php

namespace App\Services\Advertiser;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemDispute;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Single source of truth for advertiser marketplace spend.
 *
 * Rules:
 * - Gross = paid marketplace orders (wallet + card), any payment method
 * - Refunded = orders with payment_status refunded (or cancelled+refunded)
 * - Net = gross − refunded
 * - Never max(orders, ledger purchases)
 * - Publisher promo / site-feature debits are NOT marketplace spend
 * - Candle buckets use paid_at (fallback created_at) — Option A
 */
class AdvertiserSpendService
{
    public const IN_PROGRESS_STATUSES = ['pending', 'processing', 'review'];

    /**
     * Paid marketplace orders (includes later-refunded rows when not filtered).
     * payment_status enum is pending|paid|failed|refunded — only `paid` counts as settled.
     */
    public function paidOrdersQuery(int $userId): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->whereIn('payment_status', ['paid', 'refunded']);
    }

    /**
     * Currently active paid spend (not cancelled/refunded).
     */
    public function activePaidOrdersQuery(int $userId): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('payment_status', 'paid')
            ->whereNotIn('status', ['cancelled', 'rejected', 'failed']);
    }

    public function refundedOrdersQuery(int $userId): Builder
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('payment_status', 'refunded')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'cancelled')
                            ->where('payment_status', 'refunded');
                    });
            });
    }

    /**
     * @param  array{from?:?Carbon|string|null, to?:?Carbon|string|null}  $range
     */
    public function applyPaidAtRange(Builder $query, array $range = []): Builder
    {
        [$from, $to] = $this->normalizeRange($range['from'] ?? null, $range['to'] ?? null);

        if ($from) {
            $query->whereRaw('COALESCE(paid_at, created_at) >= ?', [$from->toDateTimeString()]);
        }
        if ($to) {
            $query->whereRaw('COALESCE(paid_at, created_at) <= ?', [$to->toDateTimeString()]);
        }

        return $query;
    }

    /**
     * @param  array{from?:mixed, to?:mixed}  $range
     * @return array{
     *     gross: float,
     *     refunded: float,
     *     net: float,
     *     spent: float,
     *     in_progress: float,
     *     committed: float,
     *     gross_orders: int,
     *     refunded_orders: int,
     *     spent_orders: int,
     *     in_progress_orders: int,
     *     aov_net: float
     * }
     */
    public function summary(int $userId, array $range = []): array
    {
        $grossOrders = $this->applyPaidAtRange(
            $this->paidOrdersQuery($userId)->where('payment_status', 'paid'),
            $range
        )->get(['id', 'total_amount', 'status', 'payment_status', 'paid_at', 'created_at']);

        $refundOrders = $this->applyPaidAtRange(
            $this->refundedOrdersQuery($userId),
            $range
        )->get(['id', 'total_amount']);

        // Prefer the larger of order-based vs ledger refunds (partial clawbacks may
        // only appear on the ledger; full refunds appear on both — never sum both).
        $orderRefunded = round((float) $refundOrders->sum('total_amount'), 2);
        $ledgerRefunds = $this->ledgerRefundSum($userId, $range);
        $refunded = max($orderRefunded, $ledgerRefunds);

        // Gross for net should include amounts that were later refunded in-range,
        // otherwise net under-states historical paid volume. Rebuild gross from
        // all paid+refunded in range.
        $allPaidLike = $this->applyPaidAtRange(
            $this->paidOrdersQuery($userId),
            $range
        )->get(['id', 'total_amount', 'status', 'payment_status']);

        $grossAll = round((float) $allPaidLike->sum('total_amount'), 2);
        $net = round(max(0.0, $grossAll - $refunded), 2);

        $spentOrders = $grossOrders->where('status', 'completed');
        $inProgressOrders = $grossOrders->filter(
            fn (Order $o) => in_array($o->status, self::IN_PROGRESS_STATUSES, true)
        );

        $spent = round((float) $spentOrders->sum('total_amount'), 2);
        $inProgress = round((float) $inProgressOrders->sum('total_amount'), 2);
        $spentCount = $spentOrders->count();
        $inProgressCount = $inProgressOrders->count();
        $activeCount = $spentCount + $inProgressCount;

        return [
            'gross' => $grossAll,
            'refunded' => $refunded,
            'net' => $net,
            'spent' => $spent,
            'in_progress' => $inProgress,
            'committed' => round($spent + $inProgress, 2),
            'gross_orders' => $allPaidLike->count(),
            'refunded_orders' => $refundOrders->count(),
            'spent_orders' => $spentCount,
            'in_progress_orders' => $inProgressCount,
            'aov_net' => $activeCount > 0 ? round($net / max(1, $activeCount), 2) : 0.0,
        ];
    }

    /**
     * Candle series for charts (Option A: bucket by paid_at).
     *
     * @param  'order'|'day'|'month'  $view
     * @param  array{from?:mixed, to?:mixed, fill_gaps?:bool}  $range
     * @return array{has_spend: bool, summary: array, series: list<array>}
     */
    public function candles(int $userId, string $view = 'day', array $range = []): array
    {
        $fillGaps = ! empty($range['fill_gaps']);

        $orders = $this->applyPaidAtRange(
            $this->paidOrdersQuery($userId)->with(['items.site']),
            $range
        )
            ->orderByRaw('COALESCE(paid_at, created_at)')
            ->orderBy('id')
            ->get();

        $summary = $this->summary($userId, $range);

        if ($view === 'order') {
            $series = $orders
                ->filter(fn (Order $o) => $o->payment_status === 'paid'
                    && ! in_array($o->status, ['cancelled', 'rejected', 'failed'], true))
                ->map(function (Order $order) use ($userId) {
                    $item = $order->items->first();
                    $paidAt = $order->paid_at ?? $order->created_at;
                    $isSpent = $order->status === 'completed';
                    $amount = round((float) $order->total_amount, 2);

                    return [
                        'key' => 'order-'.$order->id,
                        'label' => optional($paidAt)->format('M j').' · '.($order->order_number ?: ('#'.$order->id)),
                        'short_label' => $order->order_number ?: ('#'.$order->id),
                        'date' => optional($paidAt)->toDateString(),
                        'datetime' => optional($paidAt)->toDateTimeString(),
                        'spent' => $isSpent ? $amount : 0.0,
                        'in_progress' => $isSpent ? 0.0 : $amount,
                        'amount' => $amount,
                        'orders' => 1,
                        'spent_orders' => $isSpent ? 1 : 0,
                        'in_progress_orders' => $isSpent ? 0 : 1,
                        'website' => $item?->site_name ?? '—',
                        'status' => $order->status,
                        'order_id' => $order->id,
                        'invoice_number' => $this->taxInvoiceNumber($userId, $order),
                    ];
                })
                ->values()
                ->all();
        } else {
            $active = $orders->filter(
                fn (Order $o) => $o->payment_status === 'paid'
                    && ! in_array($o->status, ['cancelled', 'rejected', 'failed'], true)
            );

            $grouped = $active->groupBy(function (Order $o) use ($view) {
                $at = $o->paid_at ?? $o->created_at;

                return $view === 'month' ? $at->format('Y-m') : $at->toDateString();
            })->sortKeys();

            $series = $grouped->map(function (Collection $bucket, string $key) use ($view) {
                $at = $bucket->first()->paid_at ?? $bucket->first()->created_at;
                $spentOrders = $bucket->where('status', 'completed');
                $inProgressOrders = $bucket->filter(
                    fn (Order $o) => in_array($o->status, self::IN_PROGRESS_STATUSES, true)
                );

                return [
                    'key' => $key,
                    'label' => $view === 'month' ? $at->format('M Y') : $at->format('M j, Y'),
                    'short_label' => $view === 'month' ? $at->format('M Y') : $at->format('M j'),
                    'date' => $key,
                    'spent' => round((float) $spentOrders->sum('total_amount'), 2),
                    'in_progress' => round((float) $inProgressOrders->sum('total_amount'), 2),
                    'amount' => round((float) $bucket->sum('total_amount'), 2),
                    'orders' => $bucket->count(),
                    'spent_orders' => $spentOrders->count(),
                    'in_progress_orders' => $inProgressOrders->count(),
                ];
            })->values()->all();

            // Dashboard continuous timeline: pad empty day buckets between from→to.
            if ($fillGaps && $view === 'day') {
                $series = $this->fillDayGaps($series, $range);
            }
        }

        $hasSpend = collect($series)->contains(
            fn ($row) => ((float) ($row['spent'] ?? 0) + (float) ($row['in_progress'] ?? 0)) > 0
        );

        return [
            'has_spend' => $hasSpend,
            'summary' => $summary,
            'series' => $series,
            'view' => $view,
        ];
    }

    /**
     * Pad missing calendar days so a fixed window chart stays continuous.
     *
     * @param  list<array<string, mixed>>  $series
     * @param  array{from?:mixed, to?:mixed}  $range
     * @return list<array<string, mixed>>
     */
    protected function fillDayGaps(array $series, array $range): array
    {
        [$from, $to] = $this->normalizeRange($range['from'] ?? null, $range['to'] ?? null);
        if (! $from || ! $to) {
            return $series;
        }

        $byKey = collect($series)->keyBy('key');
        $filled = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            if ($byKey->has($key)) {
                $filled[] = $byKey->get($key);
            } else {
                $filled[] = [
                    'key' => $key,
                    'label' => $cursor->format('M j, Y'),
                    'short_label' => $cursor->format('M j'),
                    'date' => $key,
                    'spent' => 0.0,
                    'in_progress' => 0.0,
                    'amount' => 0.0,
                    'orders' => 0,
                    'spent_orders' => 0,
                    'in_progress_orders' => 0,
                ];
            }
            $cursor->addDay();
        }

        return $filled;
    }

    /**
     * @param  'country'|'category'|'site'|'sensitive'|'payment_method'  $dimension
     * @param  array{from?:mixed, to?:mixed}  $range
     * @return list<array{label: string, key: string, orders: int, gross: float, refunded: float, net: float, spent: float, in_progress: float}>
     */
    public function breakdown(int $userId, string $dimension, array $range = []): array
    {
        $allowed = ['country', 'category', 'site', 'sensitive', 'payment_method'];
        if (! in_array($dimension, $allowed, true)) {
            $dimension = 'payment_method';
        }

        $orders = $this->applyPaidAtRange(
            $this->paidOrdersQuery($userId)->with(['items.site']),
            $range
        )->get();

        $buckets = [];

        foreach ($orders as $order) {
            $isRefunded = $order->payment_status === 'refunded'
                || ($order->status === 'cancelled' && $order->payment_status === 'refunded');
            $isActive = $order->payment_status === 'paid'
                && ! in_array($order->status, ['cancelled', 'rejected', 'failed'], true);
            $isSpent = $isActive && $order->status === 'completed';
            $isInProgress = $isActive && in_array($order->status, self::IN_PROGRESS_STATUSES, true);

            $parts = $this->dimensionParts($order, $dimension);
            foreach ($parts as $part) {
                $key = $part['key'];
                if (! isset($buckets[$key])) {
                    $buckets[$key] = [
                        'key' => $key,
                        'label' => $part['label'],
                        'orders' => 0,
                        'gross' => 0.0,
                        'refunded' => 0.0,
                        'net' => 0.0,
                        'spent' => 0.0,
                        'in_progress' => 0.0,
                    ];
                }

                $amount = (float) ($part['amount'] ?? $order->total_amount);
                $orderTotal = (float) $order->total_amount;
                $buckets[$key]['orders']++;
                $buckets[$key]['gross'] += $amount;
                if ($isRefunded) {
                    $buckets[$key]['refunded'] += $amount;
                } elseif ($orderTotal > 0) {
                    $clawback = $this->orderClawbackCredits($order);
                    if ($clawback > 0.009) {
                        $buckets[$key]['refunded'] += $amount * ($clawback / $orderTotal);
                    }
                }
                if ($isSpent) {
                    $buckets[$key]['spent'] += $amount;
                }
                if ($isInProgress) {
                    $buckets[$key]['in_progress'] += $amount;
                }
            }
        }

        return collect($buckets)
            ->map(function (array $row) {
                $row['gross'] = round($row['gross'], 2);
                $row['refunded'] = round($row['refunded'], 2);
                $row['spent'] = round($row['spent'], 2);
                $row['in_progress'] = round($row['in_progress'], 2);
                $row['net'] = max(0, round($row['gross'] - $row['refunded'], 2));

                return $row;
            })
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    /**
     * Export rows for accounting CSV.
     *
     * @param  array{from?:mixed, to?:mixed}  $range
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $userId, array $range = []): array
    {
        $orders = $this->applyPaidAtRange(
            $this->paidOrdersQuery($userId)->with(['items.site']),
            $range
        )
            ->orderByRaw('COALESCE(paid_at, created_at)')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $item = $order->items->first();
            $site = $item?->site;
            $paidAt = $order->paid_at ?? $order->created_at;
            $isRefunded = $order->payment_status === 'refunded';
            $isActive = $order->payment_status === 'paid'
                && ! in_array($order->status, ['cancelled', 'rejected', 'failed'], true);
            $gross = round((float) $order->total_amount, 2);
            $refund = $isRefunded
                ? $gross
                : $this->orderClawbackCredits($order);
            $spent = ($isActive && $order->status === 'completed') ? $gross : 0.0;
            $inProgress = ($isActive && in_array($order->status, self::IN_PROGRESS_STATUSES, true)) ? $gross : 0.0;
            $invoiceNumber = $this->taxInvoiceNumber($userId, $order);

            $rows[] = [
                'date' => optional($paidAt)->toDateTimeString(),
                'order_number' => $order->order_number,
                'reference' => $order->reference_code,
                'site' => $item?->site_name ?? '',
                'country' => $site ? (string) ($site->countryCodes()[0] ?? $site->country ?? '') : '',
                'category' => $site ? (string) ($site->category ?? '') : '',
                'payment_method' => Invoice::paymentMethodLabel($order->payment_method),
                'gross' => $gross,
                'refund' => $refund,
                'net' => max(0, round($gross - $refund, 2)),
                'spent' => $spent,
                'in_progress' => $inProgress,
                'payment_status' => (string) $order->payment_status,
                'order_status' => (string) $order->status,
                'sensitive_type' => (string) ($item?->sensitive_type ?? $order->sensitive_type ?? ''),
                'sensitive_amount' => round((float) ($item?->additional_price ?? $order->additional_price ?? 0), 2),
                'invoice_number' => $invoiceNumber ?? '',
            ];
        }

        return $rows;
    }

    /**
     * Lifetime marketplace net spend (for wallet KPI).
     */
    public function lifetimeNet(int $userId): float
    {
        return $this->summary($userId)['net'];
    }

    /**
     * Settled deposit statuses used across reports.
     */
    public function settledDepositStatuses(): array
    {
        return ['approved', 'completed'];
    }

    protected function ledgerRefundSum(int $userId, array $range = []): float
    {
        $query = WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('type', WalletTransaction::TYPE_REFUND)
            ->where('status', 'completed')
            ->where('direction', 'credit')
            // Featured-site leftovers also write TYPE_REFUND on the same user.
            // Keep order refunds and legacy rows with no morph; skip Site/etc.
            ->where(function ($q) {
                $q->where('related_type', (new Order)->getMorphClass())
                    ->orWhereNull('related_type');
            });

        [$from, $to] = $this->normalizeRange($range['from'] ?? null, $range['to'] ?? null);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * Advertiser credits from upheld line disputes on a still-paid order.
     */
    protected function orderClawbackCredits(Order $order): float
    {
        if (! $order->id || ! OrderItemDispute::tableAvailable()) {
            return 0.0;
        }

        return round((float) OrderItemDispute::query()
            ->where('order_id', $order->id)
            ->where('status', OrderItemDispute::STATUS_UPHELD)
            ->sum('advertiser_credited'), 2);
    }

    protected function taxInvoiceNumber(int $userId, Order $order): ?string
    {
        return Invoice::query()
            ->where('user_id', $userId)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where(function ($q) use ($order) {
                $q->where('order_id', $order->id)
                    ->orWhere('reference_code', $order->reference_code)
                    ->orWhere('order_number', $order->order_number);
            })
            ->latest('id')
            ->value('invoice_number');
    }

    /**
     * @return list<array{key: string, label: string, amount?: float}>
     */
    protected function dimensionParts(Order $order, string $dimension): array
    {
        return match ($dimension) {
            'payment_method' => [[
                'key' => (string) ($order->payment_method ?: 'unknown'),
                'label' => Invoice::paymentMethodLabel($order->payment_method ?: 'Unknown'),
            ]],
            'site' => $order->items->map(function (OrderItem $item) {
                $name = $item->site_name ?: ('Site #'.($item->site_id ?: '?'));

                return [
                    'key' => 'site-'.($item->site_id ?: md5($name)),
                    'label' => $name,
                    'amount' => (float) $item->price,
                ];
            })->values()->all() ?: [[
                'key' => 'site-unknown',
                'label' => 'Unknown site',
            ]],
            'country' => $order->items->map(function (OrderItem $item) {
                $site = $item->site;
                $code = $site ? (string) ($site->countryCodes()[0] ?? $site->country ?? '') : '';
                $code = strtoupper($code ?: 'XX');

                return [
                    'key' => 'country-'.strtolower($code),
                    'label' => $code === 'XX' ? 'Unknown' : $code,
                    'amount' => (float) $item->price,
                ];
            })->values()->all() ?: [[
                'key' => 'country-xx',
                'label' => 'Unknown',
            ]],
            'category' => $order->items->map(function (OrderItem $item) {
                $cat = (string) ($item->site?->category ?: 'Uncategorized');

                return [
                    'key' => 'cat-'.md5(strtolower($cat)),
                    'label' => $cat,
                    'amount' => (float) $item->price,
                ];
            })->values()->all() ?: [[
                'key' => 'cat-none',
                'label' => 'Uncategorized',
            ]],
            'sensitive' => (function () use ($order) {
                $rows = [];
                foreach ($order->items as $item) {
                    $base = max(0, (float) $item->price - (float) ($item->additional_price ?? 0));
                    $up = (float) ($item->additional_price ?? 0);
                    $rows[] = [
                        'key' => 'sensitive-base',
                        'label' => 'Base placement',
                        'amount' => $base,
                    ];
                    if ($up > 0) {
                        $type = (string) ($item->sensitive_type ?: 'Sensitive');
                        $rows[] = [
                            'key' => 'sensitive-'.md5(strtolower($type)),
                            'label' => 'Sensitive: '.$type,
                            'amount' => $up,
                        ];
                    }
                }

                return $rows ?: [[
                    'key' => 'sensitive-base',
                    'label' => 'Base placement',
                    'amount' => (float) $order->total_amount,
                ]];
            })(),
            default => [[
                'key' => 'other',
                'label' => 'Other',
            ]],
        };
    }

    /**
     * @return array{0:?Carbon, 1:?Carbon}
     */
    protected function normalizeRange(mixed $from, mixed $to): array
    {
        $fromAt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toAt = $to ? Carbon::parse($to)->endOfDay() : null;

        if ($fromAt && $toAt && $fromAt->gt($toAt)) {
            return [$toAt->copy()->startOfDay(), $fromAt->copy()->endOfDay()];
        }

        return [$fromAt, $toAt];
    }
}
