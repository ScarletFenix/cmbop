<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SiteUrlReveal;
use App\Models\User;
use App\Services\Catalog\RevealPaceGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            return view('admin.catalog-activity', ['rows' => collect(), 'available' => false]);
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
                'exempt' => (bool) $user->catalog_reveal_exempt,
                'account_age_days' => (int) ($user->created_at?->diffInDays(now()) ?? 0),
            ];
        })->filter()->values();

        return view('admin.catalog-activity', [
            'rows' => $rows,
            'days' => $days,
            'available' => true,
            'enforcing' => (bool) config('catalog.url_reveal.pace.enforce', true),
        ]);
    }

    /**
     * Mark an account as a known heavy browser, or take that back.
     */
    public function toggleExempt(int $user): RedirectResponse
    {
        $model = User::findOrFail($user);
        $model->catalog_reveal_exempt = ! $model->catalog_reveal_exempt;
        $model->save();

        return back()->with(
            'success',
            $model->catalog_reveal_exempt
                ? $model->email.' is now exempt from catalog pace checks.'
                : $model->email.' is back under the usual pace checks.'
        );
    }
}
