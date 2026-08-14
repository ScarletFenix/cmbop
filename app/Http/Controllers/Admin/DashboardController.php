<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(private DashboardMetricsService $metrics) {}

    /**
     * Admin dashboard page (marketing uses Marketing\PanelController).
     */
    public function index()
    {
        return view('admin.dashboard');
    }

    /**
     * Top-level KPI cards + action counts (AJAX)
     */
    public function getStatistics()
    {
        try {
            return response()->json(['success' => true, 'data' => $this->remember('statistics', fn () => $this->metrics->statistics())]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard statistics error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load statistics'], 500);
        }
    }

    /**
     * Revenue + user signup series for the last N days (AJAX)
     */
    public function getTrends(Request $request)
    {
        try {
            $days = min(90, max(7, (int) $request->get('days', 30)));

            return response()->json([
                'success' => true,
                ...$this->remember('trends.'.$days, fn () => $this->metrics->trends($days)),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard trends error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load trends'], 500);
        }
    }

    /**
     * Order status + role distribution pie data (AJAX)
     */
    public function getDistributions()
    {
        try {
            return response()->json([
                'success' => true,
                ...$this->remember('distributions', fn () => $this->metrics->distributions()),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard distributions error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load distributions'], 500);
        }
    }

    /**
     * Sidebar badge counts for pending ops queues (AJAX)
     */
    public function getQueueCounts()
    {
        try {
            return response()->json([
                'success' => true,
                ...$this->remember('queue-counts', fn () => $this->metrics->queueCounts()),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard queue counts error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load queue counts'], 500);
        }
    }

    /**
     * Liability + this-month margin (same source as the finance hub).
     */
    public function getFinanceStrip()
    {
        try {
            return response()->json(['success' => true, 'data' => $this->remember('finance', fn () => $this->metrics->financeStrip())]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard finance strip error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load finance'], 500);
        }
    }

    /**
     * Items that need admin attention (AJAX)
     */
    public function getActionQueue()
    {
        try {
            return response()->json([
                'success' => true,
                ...$this->remember('action-queue', fn () => $this->metrics->actionQueue()),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard action queue error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load action queue'], 500);
        }
    }

    /**
     * Optional short-lived cache. TTL 0 (default) skips the store.
     */
    private function remember(string $key, callable $callback): mixed
    {
        $ttl = (int) config('dashboard.metrics_cache_seconds', 0);
        if ($ttl <= 0) {
            return $callback();
        }

        return Cache::remember('admin.dashboard.'.$key, $ttl, $callback);
    }
}
