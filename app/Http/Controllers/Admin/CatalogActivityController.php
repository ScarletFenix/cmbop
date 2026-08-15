<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogCopyEvent;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Catalog\RevealPaceGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Hide-mode / copy-strike queue.
 *
 * Everyday catalog browsing is not logged. This screen is for accounts that
 * mass-copied domains (warning or hide) and for hide-mode eye/visit/cart
 * unlocks. The judgement column is unlocks in the selected window against
 * paid orders in that same window — plus whether the account has ever paid
 * or completed a deposit.
 */
class CatalogActivityController extends Controller
{
    public const NO_ORDERS_UNLOCK_THRESHOLD = 100;

    public const COPY_ATTENTION_DAYS = 14;

    public function index(Request $request, RevealPaceGuard $pace): View
    {
        $days = max(1, min(90, (int) $request->integer('days', 7)));
        $copyFilter = $request->query('copy') === 'all' ? 'all' : 'attention';
        $q = trim((string) $request->query('q', ''));
        $focusUserId = max(0, (int) $request->integer('user'));

        $shared = $this->sharedViewData($days, $copyFilter, $q, $focusUserId);

        if (! Schema::hasTable('site_url_reveals')) {
            [$copyStrikeRows, $copyStrikeCapped] = $this->copyStrikeRows($request, $days, $pace);

            return view('admin.catalog-activity', array_merge($shared, [
                'rows' => collect(),
                'available' => false,
                'copyStrikeRows' => $copyStrikeRows,
                'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
                'copyStrikeCapped' => $copyStrikeCapped,
            ]));
        }

        $matchingIds = $this->matchingUserIds($q);

        $counts = SiteUrlReveal::query()
            ->select('user_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(created_at) as last_at'))
            ->where('created_at', '>=', now()->subDays($days))
            ->when($matchingIds !== null, fn ($query) => $query->whereIn('user_id', $matchingIds))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        $userIds = $counts->pluck('user_id');
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $windowStart = now()->subDays($days);
        $ordersInWindow = $this->paidOrderCounts($userIds, $windowStart);
        $ordersLifetime = $this->paidOrderCounts($userIds, null);
        $establishedIds = $this->establishedUserIds($userIds);

        $lastHour = SiteUrlReveal::query()
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', now()->subHour())
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $recent = SiteUrlReveal::query()
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', now()->subHour())
            ->orderBy('created_at')
            ->get(['user_id', 'created_at'])
            ->groupBy('user_id');

        $samples = max(5, (int) config('catalog.url_reveal.pace.regularity_samples', 15));
        $stddev = (float) config('catalog.url_reveal.pace.regularity_stddev_seconds', 1.5);

        $rows = $counts->map(function ($row) use ($users, $ordersInWindow, $ordersLifetime, $establishedIds, $pace, $lastHour, $recent, $samples, $stddev) {
            $user = $users->get($row->user_id);

            if (! $user) {
                return null;
            }

            $orders = (int) ($ordersInWindow[$row->user_id] ?? 0);
            $total = (int) $row->total;

            return [
                'user' => $user,
                'total' => $total,
                'last_at' => $this->parseDbTimestamp($row->last_at),
                'orders' => $orders,
                'orders_lifetime' => (int) ($ordersLifetime[$row->user_id] ?? 0),
                'per_order' => $orders > 0 ? round($total / $orders, 1) : null,
                'established' => $establishedIds->has((int) $row->user_id),
                'last_hour' => (int) ($lastHour[$row->user_id] ?? 0),
                'metronomic' => $pace->seriesLooksMetronomic(
                    $recent->get($row->user_id, collect())->pluck('created_at')->all(),
                    $samples,
                    $stddev
                ),
                'exempt' => $pace->isExempt($user),
                'exempt_until' => $user->catalog_reveal_exempt_until,
                'account_age_days' => (int) ($user->created_at?->diffInDays(now()) ?? 0),
            ];
        })->filter()->values();

        [$copyStrikeRows, $copyStrikeCapped] = $this->copyStrikeRows($request, $days, $pace);

        return view('admin.catalog-activity', array_merge($shared, [
            'rows' => $rows,
            'available' => true,
            'enforcing' => (bool) config('catalog.url_reveal.pace.enforce', true),
            'copyStrikeRows' => $copyStrikeRows,
            'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
            'copyStrikeCapped' => $copyStrikeCapped,
        ]));
    }

    public function show(int $user): View
    {
        $model = User::findOrFail($user);

        $copyEvents = collect();
        if (Schema::hasTable('catalog_copy_events')) {
            $copyEvents = CatalogCopyEvent::query()
                ->with('site:id,site_name,domain,site_url')
                ->where('user_id', $model->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        $reveals = collect();
        if (Schema::hasTable('site_url_reveals')) {
            $reveals = SiteUrlReveal::query()
                ->with('site:id,site_name,domain,site_url')
                ->where('user_id', $model->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        }

        return view('admin.catalog-activity-show', [
            'account' => $model,
            'status' => $model->catalogCopyStatus(),
            'copyEvents' => $copyEvents,
            'reveals' => $reveals,
            'hideHours' => max(1, (int) config('catalog.copy_strikes.hide_hours', 24)),
            'userUrl' => $this->userUrl($model->id),
        ]);
    }

    /**
     * Lift hide only — strike ladder and copy events stay.
     */
    public function liftHide(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        [$model, $hideUntilWas, $strikesWere] = $this->mutateLockedUser($user, function (User $model) {
            $hideUntilWas = $model->catalog_hide_until;
            $strikesWere = (int) ($model->catalog_copy_strike_count ?? 0);
            $model->catalog_hide_until = null;
            CatalogCopyStrikeGuard::watermarkEvents($model);
            $model->save();

            return [$model, $hideUntilWas, $strikesWere];
        });

        CatalogCopyStrikeGuard::forgetNotices((int) $model->id);
        $this->logActivity(
            'catalog_hide_lifted',
            $model->email.' is out of catalog hide mode. Strike ladder is unchanged.',
            $model,
            [
                'hide_until_was' => $hideUntilWas?->toIso8601String(),
                'strikes_were' => $strikesWere,
            ]
        );

        return back()->with(
            'success',
            $model->email.' is out of catalog hide mode — names and URLs show normally again. Strikes are unchanged.'
        );
    }

    /**
     * Reset the strike ladder. Does not lift an active hide or delete copy events.
     */
    public function resetStrikes(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        [$model, $strikesWere, $warnedAtWas, $inHide] = $this->mutateLockedUser($user, function (User $model) {
            $strikesWere = (int) ($model->catalog_copy_strike_count ?? 0);
            $warnedAtWas = $model->catalog_copy_warned_at;
            $inHide = $model->inCatalogHideMode();
            $model->catalog_copy_strike_count = 0;
            $model->catalog_copy_warned_at = null;
            CatalogCopyStrikeGuard::watermarkEvents($model);
            $model->save();

            return [$model, $strikesWere, $warnedAtWas, $inHide];
        });

        CatalogCopyStrikeGuard::forgetNotices((int) $model->id);
        $this->logActivity(
            'catalog_strikes_reset',
            $model->email.' copy-strike ladder was reset.',
            $model,
            [
                'strikes_were' => $strikesWere,
                'warned_at_was' => $warnedAtWas?->toIso8601String(),
                'still_in_hide' => $inHide,
            ]
        );

        $tail = $inHide
            ? ' Hide mode is still on until you lift it.'
            : '';

        return back()->with(
            'success',
            $model->email.' copy strikes were reset. Copy history is kept.'.$tail
        );
    }

    /**
     * Lift hide and reset strikes. Copy events are kept for forensics.
     *
     * @deprecated Use liftHide / resetStrikes. Kept so older bookmarks still work.
     */
    public function clearCopyHide(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        [$model, $hideUntilWas, $strikesWere] = $this->mutateLockedUser($user, function (User $model) {
            $hideUntilWas = $model->catalog_hide_until;
            $strikesWere = (int) ($model->catalog_copy_strike_count ?? 0);
            $model->catalog_hide_until = null;
            $model->catalog_copy_strike_count = 0;
            $model->catalog_copy_warned_at = null;
            CatalogCopyStrikeGuard::watermarkEvents($model);
            $model->save();

            return [$model, $hideUntilWas, $strikesWere];
        });

        ActivityLogger::tryLog(
            'catalog_activity.copy_hide_cleared',
            (auth()->user()?->name ?? 'Admin').' cleared catalog copy hide for '.$model->email,
            $model,
            ['user_id' => $model->id],
            $model->name
        );

        if (Schema::hasTable('catalog_copy_events')) {
            CatalogCopyEvent::query()->where('user_id', $model->id)->delete();
        }

        return back()->with(
            'success',
            $model->email.' is out of catalog hide mode — strikes reset, names and URLs show normally again. Copy history is kept.'
        );
    }

    /**
     * Grant a pace exemption, or end one early.
     *
     * Only meaningful while hide mode is on (pace is not consulted otherwise).
     */
    public function toggleExempt(int $user): RedirectResponse
    {
        $model = User::findOrFail($user);
        $minutes = max(1, (int) config('catalog.url_reveal.pace.exemption_minutes', 60));

        if ($model->catalog_reveal_exempt_until && $model->catalog_reveal_exempt_until->isFuture()) {
            $model->catalog_reveal_exempt = false;
            $model->catalog_reveal_exempt_until = null;
            $model->save();

            $this->logActivity(
                'catalog_pace_exempted',
                $model->email.' is back under the usual pace checks.',
                $model,
                ['exempt' => false]
            );

            return back()->with(
                'success',
                $model->email.' is back under the usual pace checks.'
            );
        }

        $until = now()->addMinutes($minutes);
        $model->catalog_reveal_exempt = true;
        $model->catalog_reveal_exempt_until = $until;
        $model->save();

        $this->logActivity(
            'catalog_pace_exempted',
            $model->email.' is trusted for '.$minutes.' minutes.',
            $model,
            [
                'exempt' => true,
                'exempt_until' => $until->toIso8601String(),
                'minutes' => $minutes,
            ]
        );

        return back()->with(
            'success',
            $model->email.' is trusted until '.$until->timezone(config('app.timezone'))->format('H:i').' ('.$minutes.' minutes).'
        );
    }

    /**
     * @return array{0: Collection<int, array<string, mixed>>, 1: bool}
     */
    private function copyStrikeRows(Request $request, int $days, RevealPaceGuard $pace): array
    {
        if (! $this->copyStrikeColumnsReady()) {
            return [collect(), false];
        }

        $copyFilter = $request->query('copy') === 'all' ? 'all' : 'attention';
        $q = trim((string) $request->query('q', ''));
        $focusUserId = max(0, (int) $request->integer('user'));
        $matchingIds = $this->matchingUserIds($q);
        $attentionSince = now()->subDays(self::COPY_ATTENTION_DAYS);

        $query = User::query()
            ->where(function ($outer) use ($copyFilter, $attentionSince) {
                $outer->where(function ($q) {
                    $q->whereNotNull('catalog_hide_until')
                        ->where('catalog_hide_until', '>', now());
                });

                if ($copyFilter === 'all') {
                    $outer->orWhere('catalog_copy_strike_count', '>', 0);

                    return;
                }

                $outer->orWhere(function ($q) use ($attentionSince) {
                    $q->where('catalog_copy_strike_count', '>', 0)
                        ->where(function ($recent) use ($attentionSince) {
                            $recent->where('catalog_copy_warned_at', '>=', $attentionSince)
                                ->orWhere('catalog_hide_until', '>=', $attentionSince);
                        });
                });
            })
            ->when($matchingIds !== null, fn ($q2) => $q2->whereIn('id', $matchingIds));

        $users = $query
            ->orderByRaw('CASE WHEN catalog_hide_until IS NOT NULL AND catalog_hide_until > ? THEN 0 ELSE 1 END', [now()])
            ->orderByDesc('catalog_hide_until')
            ->orderByDesc('catalog_copy_strike_count')
            ->orderByDesc('catalog_copy_warned_at')
            ->limit(101)
            ->get();

        $capped = $users->count() > 100;
        $users = $users->take(100)->values();

        // Notification deep-links use ?user= with no search. A later search
        // must not keep pinning that account via a leftover query param.
        if ($q === '' && $focusUserId > 0 && ! $users->contains(fn (User $u) => (int) $u->id === $focusUserId)) {
            $focus = User::find($focusUserId);
            if ($focus) {
                $users->push($focus);
            }
        }

        if ($users->isEmpty()) {
            return [collect(), $capped];
        }

        $userIds = $users->pluck('id');
        $windowStart = now()->subDays($days);
        $unlocks = collect();
        $lastHour = collect();

        if (Schema::hasTable('site_url_reveals')) {
            $unlocks = SiteUrlReveal::query()
                ->select('user_id', DB::raw('COUNT(*) as total'))
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', $windowStart)
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            $lastHour = SiteUrlReveal::query()
                ->select('user_id', DB::raw('COUNT(*) as total'))
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', now()->subHour())
                ->groupBy('user_id')
                ->pluck('total', 'user_id');
        }

        $ordersInWindow = $this->paidOrderCounts($userIds, $windowStart);
        $establishedIds = $this->establishedUserIds($userIds);
        $copies24h = $this->distinctCopiesSince($userIds, now()->subDay());

        $rows = $users->map(function (User $user) use ($unlocks, $lastHour, $ordersInWindow, $establishedIds, $copies24h, $pace) {
            $hideUntil = $user->catalog_hide_until;
            $status = $user->catalogCopyStatus();
            $orders = (int) ($ordersInWindow[$user->id] ?? 0);
            $total = (int) ($unlocks[$user->id] ?? 0);

            return [
                'user' => $user,
                'status' => $status,
                'strike_count' => (int) ($user->catalog_copy_strike_count ?? 0),
                'warned_at' => $user->catalog_copy_warned_at,
                'hide_until' => $hideUntil,
                'in_hide_mode' => $status === User::CATALOG_COPY_HIDDEN,
                'hide_remaining' => $this->hideRemainingLabel($hideUntil),
                'copies_24h' => (int) ($copies24h[$user->id] ?? 0),
                'total' => $total,
                'last_hour' => (int) ($lastHour[$user->id] ?? 0),
                'orders' => $orders,
                'per_order' => $orders > 0 ? round($total / $orders, 1) : null,
                'established' => $establishedIds->has((int) $user->id),
                'exempt' => $pace->isExempt($user),
                'exempt_until' => $user->catalog_reveal_exempt_until,
                'account_age_days' => (int) ($user->created_at?->diffInDays(now()) ?? 0),
                'user_url' => $this->userUrl($user->id),
            ];
        })->values();

        return [$rows, $capped];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedViewData(int $days, string $copyFilter, string $q, int $focusUserId): array
    {
        $hideHours = max(1, (int) config('catalog.copy_strikes.hide_hours', 24));
        $exemptionMinutes = max(1, (int) config('catalog.url_reveal.pace.exemption_minutes', 60));

        return [
            'days' => $days,
            'copyFilter' => $copyFilter,
            'q' => $q,
            'focusUserId' => $focusUserId,
            'hideHours' => $hideHours,
            'exemptionMinutes' => $exemptionMinutes,
            'noOrdersThreshold' => self::NO_ORDERS_UNLOCK_THRESHOLD,
            'copyAttentionDays' => self::COPY_ATTENTION_DAYS,
        ];
    }

    /**
     * @return Collection<int, int>|null
     */
    private function matchingUserIds(string $q): ?Collection
    {
        if ($q === '') {
            return null;
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        return User::query()
            ->where(function ($query) use ($like) {
                $query->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like);
            })
            ->pluck('id');
    }

    /**
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, int>
     */
    private function paidOrderCounts(Collection $userIds, ?Carbon $since): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('orders')) {
            return collect();
        }

        return Order::query()
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereIn('user_id', $userIds)
            ->where('payment_status', 'paid')
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->groupBy('user_id')
            ->pluck('total', 'user_id');
    }

    /**
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, int>
     */
    private function establishedUserIds(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $paid = collect();
        if (Schema::hasTable('orders')) {
            $paid = Order::query()
                ->whereIn('user_id', $userIds)
                ->where('payment_status', 'paid')
                ->distinct()
                ->pluck('user_id');
        }

        $deposited = collect();
        if (Schema::hasTable('deposit_requests')) {
            $deposited = DepositRequest::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('status', ['approved', 'completed'])
                ->distinct()
                ->pluck('user_id');
        }

        return $paid->merge($deposited)->map(fn ($id) => (int) $id)->unique()->flip();
    }

    /**
     * Distinct copied sites + host-only rows in the window (same rule as the guard).
     *
     * @param  Collection<int, mixed>  $userIds
     * @return Collection<int, int>
     */
    private function distinctCopiesSince(Collection $userIds, Carbon $since): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('catalog_copy_events')) {
            return collect();
        }

        $withSite = CatalogCopyEvent::query()
            ->select('user_id', DB::raw('COUNT(DISTINCT site_id) as total'))
            ->whereIn('user_id', $userIds)
            ->where('created_at', '>=', $since)
            ->whereNotNull('site_id')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

            ActivityLogger::tryLog(
                'catalog_activity.exempt_toggled',
                (auth()->user()?->name ?? 'Admin').' ended catalog pace exemption for '.$model->email,
                $model,
                ['exempt' => false, 'user_id' => $model->id],
                $model->name
            );

            return back()->with(
                'success',
                $model->email.' is back under the usual pace checks.'
            );
        }

        return $totals;
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $callback
     * @return T
     */
    private function mutateLockedUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            $model = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            return $callback($model);
        });
    }

    private function parseDbTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), 'UTC');
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return Carbon::parse($raw, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(string $action, string $description, User $subject, array $properties = []): void
    {
        try {
            ActivityLogger::log($action, $description, $subject, $properties);
        } catch (\Throwable $e) {
            Log::warning('Catalog activity log failed', [
                'action' => $action,
                'user_id' => $subject->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function hideRemainingLabel(?Carbon $until): ?string
    {
        if (! $until || ! $until->isFuture()) {
            return null;
        }

        $minutes = max(1, (int) ceil(now()->diffInMinutes($until, false)));

        if ($minutes >= 120) {
            return (int) round($minutes / 60).'h left';
        }

        return $minutes.'m left';
    }

    private function userUrl(int $userId): string
    {
        return route('admin.users.index', ['user' => $userId]).'#user-'.$userId;
    }

        return back()->with(
            'success',
            $model->email.' is trusted until '.$until->timezone(config('app.timezone'))->format('H:i').' ('.$minutes.' minutes).'
        );
    }
}
