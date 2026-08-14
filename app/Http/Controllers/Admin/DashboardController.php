<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardMetricsService;
use Illuminate\Http\Request;
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
            return response()->json(['success' => true, 'data' => $this->metrics->statistics()]);
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
            return response()->json([
                'success' => true,
                ...$this->metrics->trends((int) $request->get('days', 30)),
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
                ...$this->metrics->distributions(),
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
                ...$this->metrics->queueCounts(),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard queue counts error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load queue counts'], 500);
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
                ...$this->metrics->actionQueue(),
            ]);
        } catch (\Exception $e) {
            Log::error('Admin dashboard action queue error: '.$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Failed to load action queue'], 500);
        }
    }
}
