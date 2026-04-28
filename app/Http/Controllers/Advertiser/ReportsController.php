<?php
// app/Http/Controllers/Advertiser/ReportsController.php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        
        // Get funds activity (deposit requests) - last 50 only
        $fundsActivity = DepositRequest::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        // Add type attribute to each fund activity
        foreach ($fundsActivity as $activity) {
            $activity->type = 'deposit';
        }
        
        // Get orders - last 50 only with items
        $orders = Order::where('user_id', $userId)
            ->with('items')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        // Calculate order statistics with sensitive prices
        $orderStats = [
            'total_orders' => 0,
            'total_base_amount' => 0,
            'total_sensitive_amount' => 0,
            'total_amount' => 0,
            'orders_with_sensitive' => 0
        ];
        
        foreach ($orders as $order) {
            $orderStats['total_orders']++;
            $orderStats['total_amount'] += $order->total_amount;
            
            foreach ($order->items as $item) {
                $additionalPrice = $item->additional_price ?? 0;
                $basePrice = $item->price - $additionalPrice;
                
                $orderStats['total_base_amount'] += $basePrice;
                $orderStats['total_sensitive_amount'] += $additionalPrice;
                
                if ($additionalPrice > 0) {
                    $orderStats['orders_with_sensitive']++;
                }
            }
        }
        
        // Calculate totals from database
        $totalDeposits = DepositRequest::where('user_id', $userId)
            ->where('status', 'completed')
            ->sum('amount');
        
        $totalSpent = Order::where('user_id', $userId)
            ->where('payment_status', 'paid')
            ->sum('total_amount');
        
        $totalOrders = Order::where('user_id', $userId)->count();
        
        // Get sensitive price breakdown by type
        $sensitiveBreakdown = OrderItem::whereHas('order', function($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->whereNotNull('sensitive_type')
            ->where('additional_price', '>', 0)
            ->selectRaw('sensitive_type, SUM(additional_price) as total, COUNT(*) as count')
            ->groupBy('sensitive_type')
            ->get();
        
        // Get monthly spending with sensitive breakdown
        $monthlySpending = Order::where('user_id', $userId)
            ->where('payment_status', 'paid')
            ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(total_amount) as total')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();
        
        return view('advertiser.reports', compact(
            'fundsActivity', 
            'orders', 
            'totalDeposits', 
            'totalSpent', 
            'totalOrders',
            'orderStats',
            'sensitiveBreakdown',
            'monthlySpending'
        ));
    }
    
    /**
     * Get detailed order report with sensitive prices (AJAX)
     */
    public function getOrderReport(Request $request)
    {
        try {
            $userId = auth()->id();
            
            $query = Order::where('user_id', $userId)
                ->with('items')
                ->orderBy('created_at', 'desc');
            
            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            $orders = $query->paginate(20);
            
            // Transform data with sensitive price info
            $transformedOrders = [];
            foreach ($orders as $order) {
                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'created_at' => $order->created_at,
                    'status' => $order->status,
                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,
                    'total_amount' => $order->total_amount,
                    'items' => []
                ];
                
                $totalBase = 0;
                $totalSensitive = 0;
                
                foreach ($order->items as $item) {
                    $additionalPrice = $item->additional_price ?? 0;
                    $basePrice = $item->price - $additionalPrice;
                    
                    $totalBase += $basePrice;
                    $totalSensitive += $additionalPrice;
                    
                    $orderData['items'][] = [
                        'site_name' => $item->site_name,
                        'site_url' => $item->site_url,
                        'price' => $item->price,
                        'base_price' => $basePrice,
                        'additional_price' => $additionalPrice,
                        'sensitive_type' => $item->sensitive_type,
                        'content_link' => $item->content_link,
                        'live_url' => $item->live_url
                    ];
                }
                
                $orderData['base_total'] = $totalBase;
                $orderData['sensitive_total'] = $totalSensitive;
                
                $transformedOrders[] = $orderData;
            }
            
            return response()->json([
                'success' => true,
                'orders' => $transformedOrders,
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem()
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching order report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order report'
            ], 500);
        }
    }
    
    /**
     * Get sensitive price analytics (AJAX)
     */
    public function getSensitiveAnalytics(Request $request)
    {
        try {
            $userId = auth()->id();
            
            $query = OrderItem::whereHas('order', function($q) use ($userId) {
                $q->where('user_id', $userId);
            })->whereNotNull('sensitive_type');
            
            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            $sensitiveItems = $query->with('order')->get();
            
            $analytics = [
                'total_sensitive_orders' => $sensitiveItems->count(),
                'total_sensitive_amount' => $sensitiveItems->sum('additional_price'),
                'by_type' => []
            ];
            
            // Group by sensitive type
            $byType = $sensitiveItems->groupBy('sensitive_type');
            foreach ($byType as $type => $items) {
                $analytics['by_type'][] = [
                    'type' => $type,
                    'count' => $items->count(),
                    'total_amount' => $items->sum('additional_price'),
                    'avg_amount' => $items->avg('additional_price')
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching sensitive analytics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics'
            ], 500);
        }
    }
}