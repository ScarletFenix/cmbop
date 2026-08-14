<?php

namespace App\Services\Admin;

use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItemDispute;
use App\Models\ProblemReport;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteClaim;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\WebsiteSuggestion;
use App\Models\Withdrawal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Query layer for the admin dashboard JSON endpoints.
 *
 * Controllers stay responsible for HTTP wrapping; this class owns the counts
 * and series so KPI cards, sidebar badges, and action queues share one definition.
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
        $queues = $this->queueCounts();

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
            'live_sites' => Site::where('verified', 1)->where('active', 1)->count(),
            'unverified_sites' => $queues['unverified_sites'],
            'total_orders' => Order::count(),
            'paid_orders' => Order::where('payment_status', 'paid')->count(),
            'revenue' => (float) Order::where('payment_status', 'paid')->sum('total_amount'),
            'pending_deposits' => $queues['pending_deposits'],
            'pending_withdrawals' => $queues['pending_withdrawals'],
            'pending_payments' => $queues['pending_payments'],
            'pending_community' => $queues['pending_community'],
            'open_disputes' => $queues['open_disputes'],
            'needs_attention' => $queues['needs_attention'],
            'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            'orders_7d' => Order::where('created_at', '>=', now()->subDays(7))->count(),
            'revenue_7d' => (float) Order::where('payment_status', 'paid')
                ->whereRaw($this->paidAtSql().' >= ?', [now()->subDays(7)])
                ->sum('total_amount'),
        ];
    }

    /**
     * Revenue + user signup series for the last N days (clamped 7–90).
     *
     * Paid GMV is bucketed by COALESCE(paid_at, created_at) so an order paid
     * this week is not missing because it was created earlier.
     *
     * @return array{labels: list<string>, revenue: list<float>, signups: list<int>, orders: list<int>}
     */
    public function trends(int $days = 30): array
    {
        $days = min(90, max(7, $days));
        $start = now()->subDays($days - 1)->startOfDay();
        $paidAt = $this->paidAtSql();

        $labels = [];
        for ($i = 0; $i < $days; $i++) {
            $labels[] = $start->copy()->addDays($i)->format('Y-m-d');
        }

        $revenueRows = Order::where('payment_status', 'paid')
            ->whereRaw($paidAt.' >= ?', [$start])
            ->selectRaw('DATE('.$paidAt.') as day, SUM(total_amount) as total')
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

        $revenueByDay = $this->indexByDay($revenueRows);
        $signupsByDay = $this->indexByDay($signupRows);
        $ordersByDay = $this->indexByDay($orderRows);

        $revenue = [];
        $signups = [];
        $orders = [];
        foreach ($labels as $day) {
            $revenue[] = (float) ($revenueByDay[$day] ?? 0);
            $signups[] = (int) ($signupsByDay[$day] ?? 0);
            $orders[] = (int) ($ordersByDay[$day] ?? 0);
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
        $pendingPayments = $this->unpaidOrdersCount();
        $pendingClaims = $this->pendingCount(SiteClaim::class, 'site_claims');
        $pendingProblems = $this->pendingCount(ProblemReport::class, 'problem_reports');
        $pendingSuggestions = $this->pendingCount(Suggestion::class, 'suggestions');
        $pendingWebsites = $this->pendingCount(WebsiteSuggestion::class, 'website_suggestions');
        $pendingCommunity = $pendingClaims + $pendingProblems + $pendingSuggestions + $pendingWebsites;
        $openDisputes = $this->openDisputesCount();
        $needsAttention = $pendingDeposits
            + $pendingWithdrawals
            + $unverifiedSites
            + $pendingPayments
            + $pendingCommunity
            + $openDisputes;

        return [
            'pending_deposits' => $pendingDeposits,
            'pending_withdrawals' => $pendingWithdrawals,
            'unverified_sites' => $unverifiedSites,
            'pending_payments' => $pendingPayments,
            'pending_claims' => $pendingClaims,
            'pending_problems' => $pendingProblems,
            'pending_suggestions' => $pendingSuggestions,
            'pending_websites' => $pendingWebsites,
            'pending_community' => $pendingCommunity,
            'open_disputes' => $openDisputes,
            'needs_attention' => $needsAttention,
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
                // deposits.show is JSON for the list-page modal; the HTML queue is the working page.
                'url' => route('admin.deposits'),
            ]);

        $withdrawals = Withdrawal::with('user:id,name,email')
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($w) => [
                'id' => $w->id,
                'user' => $w->user?->name ?? 'Unknown',
                'email' => $w->user?->email,
                'amount' => (float) $w->amount,
                'method' => $w->payment_method,
                'status' => $w->status,
                'date' => optional($w->created_at)->format('d M Y H:i'),
                // withdrawals.show is JSON for the list-page modal; the HTML queue is the working page.
                'url' => route('admin.withdrawals'),
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
                'url' => route('admin.sites.edit', $s->id),
            ]);

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'sites' => $sites,
        ];
    }

    private function unpaidOrdersCount(): int
    {
        return Order::where(function ($q) {
            $q->whereNull('payment_status')
                ->orWhereNotIn('payment_status', ['paid', 'refunded']);
        })->whereIn('status', ['pending', 'processing', 'review'])->count();
    }

    private function openDisputesCount(): int
    {
        if (! OrderItemDispute::tableAvailable()) {
            return 0;
        }

        return OrderItemDispute::where('status', OrderItemDispute::STATUS_OPEN)->count();
    }

    /**
     * @param  class-string  $model
     */
    private function pendingCount(string $model, string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return $model::where('status', 'pending')->count();
    }

    private function paidAtSql(): string
    {
        return 'COALESCE(paid_at, created_at)';
    }

    /**
     * DATE() keys can come back as Y-m-d or a datetime string depending on driver.
     *
     * @param  Collection<string, mixed>  $rows
     * @return array<string, mixed>
     */
    private function indexByDay($rows): array
    {
        $indexed = [];
        foreach ($rows as $day => $total) {
            $indexed[Carbon::parse((string) $day)->toDateString()] = $total;
        }

        return $indexed;
    }
}
