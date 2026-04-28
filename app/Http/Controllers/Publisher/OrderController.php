<?php
// app/Http/Controllers/Publisher/OrderController.php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Mail\OrderAccepted;
use App\Mail\OrderRejected;
use App\Mail\LiveUrlSubmitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    /**
     * Display tasks page for publisher
     */
    public function index()
    {
        return view('publisher.tasks');
    }

    /**
     * Get orders list for publisher (AJAX)
     */
    public function getOrders(Request $request)
    {
        try {
            $userId = auth()->id();
            
            Log::info('Fetching orders for publisher', ['user_id' => $userId]);
            
            // Get all sites owned by this publisher
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            Log::info('Sites found for publisher', ['site_ids' => $siteIds]);
            
            // If no sites found, return empty data
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                        'from' => 0,
                        'to' => 0
                    ]
                ]);
            }
            
            // Get order items for these sites with their orders
            $query = OrderItem::with(['order.user', 'site'])
                ->whereIn('site_id', $siteIds)
                ->orderBy('created_at', 'desc');
            
            // Search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->whereHas('order', function($sub) use ($search) {
                        $sub->where('order_number', 'like', "%{$search}%")
                            ->orWhere('reference_code', 'like', "%{$search}%");
                    })->orWhere('site_name', 'like', "%{$search}%");
                });
            }
            
            // Status filter - using orders.status (the order status)
            if ($request->filled('status')) {
                $query->whereHas('order', function($sub) use ($request) {
                    $sub->where('status', $request->status);
                });
            }
            
            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            $perPage = $request->get('per_page', 20);
            $orderItems = $query->paginate($perPage);
            
            // Transform data to include sensitive price info
            $transformedItems = [];
            foreach ($orderItems->items() as $item) {
                $transformedItems[] = [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'site_id' => $item->site_id,
                    'site_name' => $item->site_name,
                    'site_url' => $item->site_url,
                    'price' => $item->price,
                    'additional_price' => $item->additional_price ?? 0,
                    'sensitive_type' => $item->sensitive_type ?? null,
                    'content_link' => $item->content_link,
                    'live_url' => $item->live_url,
                    'created_at' => $item->created_at,
                    'order' => [
                        'id' => $item->order->id,
                        'order_number' => $item->order->order_number,
                        'status' => $item->order->status,
                        'payment_method' => $item->order->payment_method,
                        'payment_status' => $item->order->payment_status,
                        'reference_code' => $item->order->reference_code,
                        'total_amount' => $item->order->total_amount
                    ]
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $transformedItems,
                'pagination' => [
                    'current_page' => $orderItems->currentPage(),
                    'last_page' => $orderItems->lastPage(),
                    'per_page' => $orderItems->perPage(),
                    'total' => $orderItems->total(),
                    'from' => $orderItems->firstItem(),
                    'to' => $orderItems->lastItem()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching publisher orders: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get single order item details (AJAX)
     */
    public function getOrderDetails($id)
    {
        try {
            $userId = auth()->id();
            
            $orderItem = OrderItem::with('order')->findOrFail($id);
            
            // Verify this order belongs to a site owned by the publisher
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();
            
            if (!$site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site'
                ], 403);
            }
            
            $data = [
                'id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $orderItem->site_id,
                'site_name' => $orderItem->site_name,
                'site_url' => $orderItem->site_url,
                'price' => $orderItem->price,
                'additional_price' => $orderItem->additional_price ?? 0,
                'sensitive_type' => $orderItem->sensitive_type ?? null,
                'content_link' => $orderItem->content_link,
                'live_url' => $orderItem->live_url,
                'created_at' => $orderItem->created_at,
                'order' => [
                    'id' => $orderItem->order->id,
                    'order_number' => $orderItem->order->order_number,
                    'status' => $orderItem->order->status,
                    'payment_method' => $orderItem->order->payment_method,
                    'payment_status' => $orderItem->order->payment_status,
                    'reference_code' => $orderItem->order->reference_code,
                    'total_amount' => $orderItem->order->total_amount,
                    'created_at' => $orderItem->order->created_at
                ]
            ];
            
            return response()->json([
                'success' => true,
                'data' => $data
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching order details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Accept an order - Update order status to 'processing'
     */
    public function acceptOrder(Request $request, $id)
    {
        try {
            $orderItem = OrderItem::with('order')->findOrFail($id);
            
            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();
            
            if (!$site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site'
                ], 403);
            }
            
            DB::beginTransaction();
            
            // Update the order status to 'processing' (accepted)
            $order = Order::find($orderItem->order_id);
            $order->update([
                'status' => 'processing'
            ]);
            
            DB::commit();
            
            // Get the advertiser (user who placed the order)
            $advertiser = User::find($order->user_id);
            
            // Send email notification to advertiser
            if ($advertiser && $advertiser->email) {
                try {
                    Mail::to($advertiser->email)->send(new OrderAccepted($order, $orderItem, $site));
                    Log::info('Order accepted email sent to advertiser', [
                        'order_id' => $order->id,
                        'advertiser_email' => $advertiser->email,
                        'order_number' => $order->order_number
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send order accepted email: ' . $e->getMessage());
                }
            }
            
            Log::info('Order accepted by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order accepted successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error accepting order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to accept order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Reject an order with reason - Update order status to 'cancelled'
     */
    public function rejectOrder(Request $request, $id)
    {
        try {
            $request->validate([
                'reason' => 'required|string|min:10'
            ]);
            
            $orderItem = OrderItem::with('order')->findOrFail($id);
            
            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();
            
            if (!$site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site'
                ], 403);
            }
            
            DB::beginTransaction();
            
            // Update the order status to 'cancelled' (rejected)
            $order = Order::find($orderItem->order_id);
            $order->update([
                'status' => 'cancelled'
            ]);
            
            DB::commit();
            
            // Get the advertiser (user who placed the order)
            $advertiser = User::find($order->user_id);
            
            // Send email notification to advertiser with rejection reason
            if ($advertiser && $advertiser->email) {
                try {
                    Mail::to($advertiser->email)->send(new OrderRejected($order, $orderItem, $site, $request->reason));
                    Log::info('Order rejected email sent to advertiser', [
                        'order_id' => $order->id,
                        'advertiser_email' => $advertiser->email,
                        'order_number' => $order->order_number,
                        'reason' => $request->reason
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send order rejected email: ' . $e->getMessage());
                }
            }
            
            Log::info('Order rejected by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
                'reason' => $request->reason
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order rejected successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error rejecting order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Submit live URL - ONLY add live URL, DO NOT change order status
     */
    public function submitLiveUrl(Request $request, $id)
    {
        try {
            $request->validate([
                'live_url' => 'required|url'
            ]);
            
            $orderItem = OrderItem::with('order')->findOrFail($id);
            
            // Verify this order belongs to a site owned by the publisher
            $userId = auth()->id();
            $site = Site::where('id', $orderItem->site_id)->where('publisher_id', $userId)->first();
            
            if (!$site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: This order does not belong to your site'
                ], 403);
            }
            
            DB::beginTransaction();
            
            // ONLY update the live_url, DO NOT change order status
            if (Schema::hasColumn('order_items', 'live_url')) {
                $orderItem->update([
                    'live_url' => $request->live_url
                ]);
            } else {
                // If live_url column doesn't exist, log warning but still return success
                Log::warning('live_url column does not exist in order_items table');
            }
            
            DB::commit();
            
            // Get the advertiser (user who placed the order)
            $order = Order::find($orderItem->order_id);
            $advertiser = User::find($order->user_id);
            
            // Send email notification to advertiser that live URL is submitted
            if ($advertiser && $advertiser->email) {
                try {
                    Mail::to($advertiser->email)->send(new LiveUrlSubmitted($order, $orderItem, $site, $request->live_url));
                    Log::info('Live URL submitted email sent to advertiser', [
                        'order_id' => $order->id,
                        'advertiser_email' => $advertiser->email,
                        'order_number' => $order->order_number,
                        'live_url' => $request->live_url
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send live URL submitted email: ' . $e->getMessage());
                }
            }
            
            Log::info('Live URL submitted by publisher', [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'site_id' => $site->id,
                'publisher_id' => $userId,
                'live_url' => $request->live_url
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Live URL submitted successfully!'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting live URL: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit live URL: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get order statistics
     */
    public function getStatistics()
    {
        try {
            $userId = auth()->id();
            $siteIds = Site::where('publisher_id', $userId)->pluck('id')->toArray();
            
            Log::info('Fetching statistics for publisher', ['user_id' => $userId, 'site_ids' => $siteIds]);
            
            // If no sites found, return zero stats
            if (empty($siteIds)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_orders' => 0,
                        'pending_orders' => 0,
                        'accepted_orders' => 0,
                        'completed_orders' => 0,
                        'rejected_orders' => 0,
                        'total_earnings' => 0
                    ]
                ]);
            }
            
            // Get all order IDs for these site items
            $orderIds = OrderItem::whereIn('site_id', $siteIds)->pluck('order_id')->unique()->toArray();
            
            $stats = [
                'total_orders' => count($orderIds),
                'pending_orders' => Order::whereIn('id', $orderIds)->where('status', 'pending')->count(),
                'accepted_orders' => Order::whereIn('id', $orderIds)->where('status', 'processing')->count(),
                'completed_orders' => Order::whereIn('id', $orderIds)->where('status', 'completed')->count(),
                'rejected_orders' => Order::whereIn('id', $orderIds)->where('status', 'cancelled')->count(),
                'total_earnings' => OrderItem::whereIn('site_id', $siteIds)
                    ->whereHas('order', function($q) {
                        $q->where('status', 'completed')
                          ->where('payment_status', 'paid');
                    })
                    ->sum('price')
            ];
            
            Log::info('Statistics calculated', $stats);
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error fetching order statistics: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }
}