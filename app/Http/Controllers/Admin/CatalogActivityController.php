<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogCopyEvent;
use App\Models\Order;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\RevealPaceGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who has been taking publisher domains, and does it look like shopping.
 *
 * Pace checks will occasionally flag a genuine agency, so this exists to answer
 * that in one glance rather than by reading logs. The column that actually
 * settles it is addresses opened against orders placed: five hundred and twenty
 * orders is your best customer; five hundred and nothing, three weeks running,
 * is someone else's research project.
 */
class CatalogActivityController extends Controller
{
    public function index(Request $request, RevealPaceGuard $pace)
    {
        if (! Schema::hasTable('site_url_reveals')) {
            return view('admin.catalog-activity', [
                'rows' => collect(),
                'available' => false,
                'copyStrikeRows' => $this->copyStrikeRows(),
                'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
            ]);
        }

        $days = max(1, min(90, (int) $request->integer('days', 7)));

        $counts = SiteUrlReveal::query()
            ->select('user_id', DB::raw('COUNT(*) as total'), DB::raw('MAX(created_at) as last_at'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        $users = User::whereIn('id', $counts->pluck('user_id'))->get()->keyBy('id');

        $orderCounts = Order::query()
            ->select('user_id', DB::raw('COUNT(*) as total'))
            ->whereIn('user_id', $counts->pluck('user_id'))
            ->where('payment_status', 'paid')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $userIds = $counts->pluck('user_id');

        // Two more queries for the whole table rather than two per row: this list
        // is ranked by activity, so without batching the busiest install has the
        // slowest page exactly when an admin needs it most.
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

        $rows = $counts->map(function ($row) use ($users, $orderCounts, $pace, $lastHour, $recent, $samples, $stddev) {
            $user = $users->get($row->user_id);

            if (! $user) {
                return null;
            }

            $orders = (int) ($orderCounts[$row->user_id] ?? 0);

            return [
                'user' => $user,
                'total' => (int) $row->total,
                'last_at' => $row->last_at,
                'orders' => $orders,
                // The ratio is the judgement call, so show it rather than making
                // an admin do the division.
                'per_order' => $orders > 0 ? round($row->total / $orders, 1) : null,
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

        return view('admin.catalog-activity', [
            'rows' => $rows,
            'days' => $days,
            'available' => true,
            'enforcing' => (bool) config('catalog.url_reveal.pace.enforce', true),
            'copyStrikeRows' => $this->copyStrikeRows(),
            'copyStrikesAvailable' => $this->copyStrikeColumnsReady(),
        ]);
    }

    /**
     * Lift copy-strike hide mode and reset the strike ladder for one account.
     *
     * Reveal history is untouched — only the clipboard-harvest penalty is cleared.
     */
    public function clearCopyHide(int $user): RedirectResponse
    {
        if (! $this->copyStrikeColumnsReady()) {
            return back()->with('error', 'Copy-strike columns are not available yet — run migrations.');
        }

        $model = User::findOrFail($user);

        $model->catalog_hide_until = null;
        $model->catalog_copy_strike_count = 0;
        $model->catalog_copy_warned_at = null;
        $model->save();

        if (Schema::hasTable('catalog_copy_events')) {
            CatalogCopyEvent::query()->where('user_id', $model->id)->delete();
        }

        return back()->with(
            'success',
            $model->email.' is out of catalog hide mode — strikes reset, names and URLs show normally again.'
        );
    }

    /**
     * Accounts currently in hide mode, or carrying a copy warning/strike.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function copyStrikeRows()
    {
        if (! $this->copyStrikeColumnsReady()) {
            return collect();
        }

        $users = User::query()
            ->where(function ($q) {
                $q->where('catalog_copy_strike_count', '>', 0)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('catalog_hide_until')
                            ->where('catalog_hide_until', '>', now())
                            ->where('catalog_hide_until', '>=', User::PLAUSIBLE_SQL_DATETIME_FLOOR)
                            ->where('catalog_hide_until', '<=', User::PLAUSIBLE_SQL_DATETIME_CEIL);
                    });
            })
            ->orderByRaw(
                'CASE WHEN catalog_hide_until IS NOT NULL AND catalog_hide_until > ? AND catalog_hide_until >= ? AND catalog_hide_until <= ? THEN 0 ELSE 1 END',
                [now(), User::PLAUSIBLE_SQL_DATETIME_FLOOR, User::PLAUSIBLE_SQL_DATETIME_CEIL]
            )
            ->orderByDesc('catalog_hide_until')
            ->orderByDesc('catalog_copy_strike_count')
            ->orderByDesc('catalog_copy_warned_at')
            ->limit(100)
            ->get();

        if ($users->isEmpty()) {
            return collect();
        }

        $copyCounts = collect();
        if (Schema::hasTable('catalog_copy_events')) {
            $window = max(30, (int) config('catalog.copy_strikes.window_seconds', 120));
            $copyCounts = CatalogCopyEvent::query()
                ->select('user_id', DB::raw('COUNT(*) as recent_copies'))
                ->whereIn('user_id', $users->pluck('id'))
                ->where('created_at', '>=', now()->subSeconds($window))
                ->where('created_at', '<=', CatalogCopyEvent::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->groupBy('user_id')
                ->pluck('recent_copies', 'user_id');
        }

        return $users->map(function (User $user) use ($copyCounts) {
            $hideUntil = $user->catalog_hide_until;
            $inHide = $user->inCatalogHideMode();

            return [
                'user' => $user,
                'strike_count' => (int) ($user->catalog_copy_strike_count ?? 0),
                'warned_at' => $user->catalog_copy_warned_at,
                'hide_until' => $hideUntil,
                'in_hide_mode' => $inHide,
                'recent_copies' => (int) ($copyCounts[$user->id] ?? 0),
                'account_age_days' => (int) ($user->created_at?->diffInDays(now()) ?? 0),
            ];
        })->values();
    }

    private function copyStrikeColumnsReady(): bool
    {
        try {
            return Schema::hasColumn('users', 'catalog_copy_strike_count')
                && Schema::hasColumn('users', 'catalog_hide_until');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Grant a one-hour pace exemption, or end one early.
     *
     * Reveal history is never cleared — only whether the pace guard applies.
     */
    public function toggleExempt(int $user): RedirectResponse
    {
        $model = User::findOrFail($user);
        $minutes = max(1, (int) config('catalog.url_reveal.pace.exemption_minutes', 60));

        if ($model->catalog_reveal_exempt_until && $model->catalog_reveal_exempt_until->isFuture()) {
            $model->catalog_reveal_exempt = false;
            $model->catalog_reveal_exempt_until = null;
            $model->save();

            return back()->with(
                'success',
                $model->email.' is back under the usual pace checks.'
            );
        }

        $until = now()->addMinutes($minutes);
        $model->catalog_reveal_exempt = true;
        $model->catalog_reveal_exempt_until = $until;
        $model->save();

        return back()->with(
            'success',
            $model->email.' is trusted until '.$until->timezone(config('app.timezone'))->format('H:i').' ('.$minutes.' minutes).'
        );
    }
}
