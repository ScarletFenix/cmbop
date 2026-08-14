<?php

namespace App\Services\Admin;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\User;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Query layer for the admin dashboard JSON endpoints.
 *
 * Controllers stay responsible for HTTP wrapping; this class owns the counts
 * and series so later dashboard work can reuse one definition of each metric.
 */
class DashboardMetricsService
{
    /**
     * Top-level KPI cards + action counts.
     *
     * @return array<string, int|float>
     */
    public function statistics(): array
    {
        $advertiserRoleId = Role::where('name', 'advertiser')->value('id');
        $publisherRoleId = Role::where('name', 'publisher')->value('id');
        $adminRoleId = Role::where('name', 'admin')->value('id');

        return [
            'total_users' => User::count(),
            'advertisers' => $advertiserRoleId
                ? (int) DB::table('role_user')->where('role_id', $advertiserRoleId)->distinct()->count('user_id')
                : 0,
            'publishers' => $publisherRoleId
                ? (int) DB::table('role_user')->where('role_id', $publisherRoleId)->distinct()->count('user_id')
                : 0,
            'admins' => $adminRoleId
                ? (int) DB::table('role_user')->where('role_id', $adminRoleId)->distinct()->count('user_id')
                : 0,
            'total_sites' => Site::count(),
            'verified_sites' => Site::where('verified', 1)->count(),
            'unverified_sites' => Site::query()->needsAdminReview()->count(),
            'total_orders' => Order::count(),
            'paid_orders' => Order::where('payment_status', 'paid')->count(),
            'revenue' => (float) Order::where('payment_status', 'paid')->sum('total_amount'),
            'pending_deposits' => DepositRequest::where('status', 'pending')->count(),
            'pending_withdrawals' => Withdrawal::whereIn('status', ['pending', 'processing'])->count(),
            'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'orders_7d' => Order::where('created_at', '>=', now()->subDays(7))->count(),
            'revenue_7d' => (float) Order::where('payment_status', 'paid')
                ->where('created_at', '>=', now()->subDays(7))
                ->sum('total_amount'),
        ];
    }

    /**
     * Revenue + user signup series for the last N days (clamped 7–90).
     *
     * @return array{labels: list<string>, revenue: list<float>, signups: list<int>, orders: list<int>}
     */
    public function trends(int $days = 30): array
    {
        $days = min(90, max(7, $days));
        $start = now()->subDays($days - 1)->startOfDay();

        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $start->copy()->addDays($i)->format('Y-m-d');
        }

        $revenueRows = Order::where('payment_status', 'paid')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $signupRows = User::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $orderRows = Order::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $revenue = [];
        $signups = [];
        $orders = [];
        foreach ($labels as $day) {
            $revenue[] = (float) ($revenueRows[$day] ?? 0);
            $signups[] = (int) ($signupRows[$day] ?? 0);
            $orders[] = (int) ($orderRows[$day] ?? 0);
        }

        return [
            'labels' => array_map(fn ($d) => Carbon::parse($d)->format('M j'), $labels),
            'revenue' => $revenue,
            'signups' => $signups,
            'orders' => $orders,
        ];
    }

    /**
     * Order status + role distribution pie data.
     *
     * @return array{orders: array{labels: mixed, values: mixed}, roles: array{labels: mixed, values: mixed}}
     */
    public function distributions(): array
    {
        $orderStatus = Order::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $roleCounts = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('roles.name', DB::raw('COUNT(DISTINCT role_user.user_id) as total'))
            ->groupBy('roles.name')
            ->pluck('total', 'name');

        return [
            'orders' => [
                'labels' => $orderStatus->keys()->map(fn ($s) => ucfirst($s))->values(),
                'values' => $orderStatus->values()->map(fn ($v) => (int) $v)->values(),
            ],
            'roles' => [
                'labels' => $roleCounts->keys()->map(fn ($s) => ucfirst($s))->values(),
                'values' => $roleCounts->values()->map(fn ($v) => (int) $v)->values(),
            ],
        ];
    }

    /**
     * Sidebar badge counts for pending ops queues.
     *
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        $pendingDeposits = DepositRequest::where('status', 'pending')->count();
        $pendingWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing'])->count();
        // Ready-for-admin queue only (exclude unfinished awaiting_details drafts)
        $unverifiedSites = Site::query()->needsAdminReview()->count();
        $pendingPayments = Order::where(function ($q) {
            $q->whereNull('payment_status')
                ->orWhereNotIn('payment_status', ['paid', 'refunded']);
        })->whereIn('status', ['pending', 'processing', 'review'])->count();
        $pendingClaims = SiteClaim::where('status', 'pending')->count();

        return [
            'pending_deposits' => $pendingDeposits,
            'pending_withdrawals' => $pendingWithdrawals,
            'unverified_sites' => $unverifiedSites,
            'pending_payments' => $pendingPayments,
            'pending_claims' => $pendingClaims,
        ];
    }

    /**
     * Items that need admin attention (top 5 per queue).
     *
     * @return array{deposits: mixed, withdrawals: mixed, sites: mixed}
     */
    public function actionQueue(): array
    {
        $deposits = DepositRequest::with('user:id,name,email')
            ->where('status', 'pending')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'user' => $d->user?->name ?? 'Unknown',
                'email' => $d->user?->email,
                'amount' => (float) $d->amount,
                'method' => $d->payment_method,
                'date' => optional($d->created_at)->format('d M Y H:i'),
            ]);

        $withdrawals = Withdrawal::with('user:id,name,email')
            ->where('status', 'pending')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'user' => $w->user?->name ?? 'Unknown',
                'email' => $w->user?->email,
                'amount' => (float) $w->amount,
                'method' => $w->payment_method,
                'date' => optional($w->created_at)->format('d M Y H:i'),
            ]);

        $sites = Site::with('publisher:id,name,email')
            ->needsAdminReview()
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'site_name' => $s->site_name,
                'site_url' => $s->site_url,
                'publisher' => $s->publisher?->name ?? 'Unknown',
                'date' => optional($s->created_at)->format('d M Y'),
            ]);

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'sites' => $sites,
        ];
    }
}
