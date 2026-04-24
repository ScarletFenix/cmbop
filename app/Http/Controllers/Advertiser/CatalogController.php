<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\UserFavorite;
use App\Models\UserBlacklist;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();
        
        // Get favorites and blacklist from DATABASE
        $favorites = UserFavorite::where('user_id', $userId)->pluck('site_id')->toArray();
        $blacklist = UserBlacklist::where('user_id', $userId)->pluck('site_id')->toArray();
        
        $query = Site::where('active', 1);
        
        // Check if blacklist filter is active
        $showBlacklistedOnly = $request->filled('blacklist_filter') && $request->blacklist_filter == 1;
        
        if ($showBlacklistedOnly) {
            // Show ONLY blacklisted sites
            if (!empty($blacklist)) {
                $query->whereIn('id', $blacklist);
            } else {
                $query->whereRaw('1 = 0');
            }
        } else {
            // Normal view: Exclude blacklisted sites
            if (!empty($blacklist)) {
                $query->whereNotIn('id', $blacklist);
            }
        }

        // 🔍 Search (by URL or category)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('site_url', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
                  ->orWhere('site_name', 'like', "%{$search}%");
            });
        }

        // ✅ Verified filter
        if ($request->filled('verified') && $request->verified == 1) {
            $query->where('verified', 1);
        }
        
        // ⭐ Favorites filter
        if ($request->filled('favorites_filter') && $request->favorites_filter == 1) {
            if (!empty($favorites)) {
                $query->whereIn('id', $favorites);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 📊 DA range
        if ($request->filled('da_min')) {
            $query->where('da', '>=', (int)$request->da_min);
        }
        if ($request->filled('da_max')) {
            $query->where('da', '<=', (int)$request->da_max);
        }

        // 📊 DR range
        if ($request->filled('dr_min')) {
            $query->where('dr', '>=', (int)$request->dr_min);
        }
        if ($request->filled('dr_max')) {
            $query->where('dr', '<=', (int)$request->dr_max);
        }

        // 📊 Traffic range
        if ($request->filled('traffic_min')) {
            $query->where('traffic', '>=', (int)$request->traffic_min);
        }
        if ($request->filled('traffic_max')) {
            $query->where('traffic', '<=', (int)$request->traffic_max);
        }

        // 🌍 Language filter
        if ($request->filled('language') && !empty($request->language)) {
            $query->where('language', $request->language);
        }

        // ✅ Pagination (20 per page)
        $sites = $query->latest()->paginate(20)->withQueryString();

        // Get unique languages for the filter dropdown
        $availableLanguages = Site::where('active', 1)
            ->whereNotNull('language')
            ->where('language', '!=', '')
            ->select('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language');
        
        // Get cart from SESSION
        $cart = session()->get('cart', []);

        // Pass the filter state to the view
        $showBlacklistedOnly = $showBlacklistedOnly;

        return view('advertiser.catalog', compact('sites', 'availableLanguages', 'favorites', 'blacklist', 'cart', 'showBlacklistedOnly'));
    }
    
    /**
     * Save favorites to DATABASE
     */
    public function saveFavorites(Request $request)
    {
        try {
            $userId = auth()->id();
            $favorites = $request->favorites;
            
            UserFavorite::where('user_id', $userId)->delete();
            
            foreach ($favorites as $siteId) {
                UserFavorite::create([
                    'user_id' => $userId,
                    'site_id' => $siteId
                ]);
            }
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving favorites: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Save blacklist to DATABASE
     */
    public function saveBlacklist(Request $request)
    {
        try {
            $userId = auth()->id();
            $blacklist = $request->blacklist;
            
            UserBlacklist::where('user_id', $userId)->delete();
            
            foreach ($blacklist as $siteId) {
                UserBlacklist::create([
                    'user_id' => $userId,
                    'site_id' => $siteId
                ]);
            }
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving blacklist: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Save cart to SESSION
     */
    public function saveCart(Request $request)
    {
        try {
            session()->put('cart', $request->cart);
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error saving cart: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Get cart from SESSION
     */
    public function getCart(Request $request)
    {
        $cart = session()->get('cart', []);
        return response()->json($cart);
    }
    
    /**
     * Add to cart (SESSION)
     */
    public function addToCart(Request $request)
    {
        try {
            $id = $request->id;
            $price = $request->price;
            $name = $request->name;
            
            $cart = session()->get('cart', []);
            
            $existingItem = null;
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id) {
                    $existingItem = $key;
                    break;
                }
            }
            
            if ($existingItem !== null) {
                $cart[$existingItem]['quantity']++;
            } else {
                $cart[] = [
                    'id' => $id,
                    'name' => $name,
                    'price' => $price,
                    'quantity' => 1
                ];
            }
            
            session()->put('cart', $cart);
            
            $cartCount = array_sum(array_column($cart, 'quantity'));
            $cartTotal = array_sum(array_map(function($item) {
                return $item['price'] * $item['quantity'];
            }, $cart));
            
            return response()->json([
                'success' => true,
                'cart_count' => $cartCount,
                'cart_total' => $cartTotal
            ]);
        } catch (\Exception $e) {
            Log::error('Error adding to cart: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Remove from cart (SESSION)
     */
    public function removeFromCart(Request $request)
    {
        try {
            $id = $request->id;
            $cart = session()->get('cart', []);
            
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id) {
                    unset($cart[$key]);
                    break;
                }
            }
            
            session()->put('cart', array_values($cart));
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error removing from cart: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Update cart quantity (SESSION)
     */
    public function updateCartQuantity(Request $request)
    {
        try {
            $id = $request->id;
            $quantity = $request->quantity;
            $cart = session()->get('cart', []);
            
            foreach ($cart as $key => $item) {
                if ($item['id'] == $id) {
                    if ($quantity <= 0) {
                        unset($cart[$key]);
                    } else {
                        $cart[$key]['quantity'] = $quantity;
                    }
                    break;
                }
            }
            
            session()->put('cart', array_values($cart));
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Error updating cart: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Clear cart (SESSION)
     */
    public function clearCart(Request $request)
    {
        session()->forget('cart');
        return response()->json(['success' => true]);
    }
    
    /**
     * Checkout page
     */
    public function checkout()
    {
        $cart = session()->get('cart', []);
        
        if (empty($cart)) {
            return redirect()->route('advertiser.catalog')->with('error', 'Your cart is empty.');
        }
        
        // Get full site details for items in cart
        $cartItems = [];
        foreach ($cart as $item) {
            $site = Site::where('id', $item['id'])->where('active', 1)->first();
            if ($site) {
                $cartItems[] = [
                    'id' => $site->id,
                    'name' => $site->site_name,
                    'url' => $site->site_url,
                    'price' => $site->price,
                    'quantity' => $item['quantity'],
                    'total' => $site->price * $item['quantity']
                ];
            }
        }
        
        $total = array_sum(array_column($cartItems, 'total'));
        
        return view('advertiser.checkout', compact('cartItems', 'total'));
    }
    
    /**
     * Process order - Creates orders ONLY after successful payment for card payments
     */
    public function processOrder(Request $request)
    {
        // Handle Stripe GET callback (after payment)
        if ($request->isMethod('get')) {
            return $this->handleStripeSuccess($request);
        }
        
        try {
            $cart = session()->get('cart', []);
            
            if (empty($cart)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your cart is empty.'
                ]);
            }
            
            $userId = auth()->id();
            $paymentMethod = $request->payment_method;
            $contentLinks = $request->content_links;
            $userReferenceCode = $request->reference_code;
            
            // Generate reference code
            $referenceCode = $userReferenceCode ?? str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            // For manual payment methods (wise, crypto, bank) - create orders immediately
            if (in_array($paymentMethod, ['wise', 'crypto', 'bank', 'wallet'])) {
                return $this->createOrdersImmediately($request, $cart, $paymentMethod, $contentLinks, $referenceCode, $userId);
            }
            
            // For card payments - DON'T create orders yet, just validate and store pending info
            if ($paymentMethod === 'card') {
                // Validate content links
                if (!$contentLinks || empty($contentLinks)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Content links are required. Please fill in all Google Docs links.'
                    ]);
                }
                
                // Validate all content links
                $expandedOrders = [];
                foreach ($cart as $item) {
                    for ($i = 0; $i < $item['quantity']; $i++) {
                        $expandedOrders[] = [
                            'id' => $item['id'],
                            'name' => $item['name'],
                            'price' => $item['price'],
                            'copy_number' => $i + 1
                        ];
                    }
                }
                
                $orderIndex = 0;
                foreach ($expandedOrders as $orderItem) {
                    $site = Site::where('id', $orderItem['id'])->where('active', 1)->first();
                    if (!$site) {
                        throw new \Exception("Site not found: " . $orderItem['name']);
                    }
                    
                    if (!isset($contentLinks[$site->id]) || !isset($contentLinks[$site->id][$orderIndex])) {
                        throw new \Exception("Content link required for copy #" . ($orderIndex + 1) . " of: " . $site->site_name);
                    }
                    
                    $link = $contentLinks[$site->id][$orderIndex];
                    
                    if (!preg_match('/^https?:\/\/(docs\.google\.com|drive\.google\.com)\/.*$/i', $link)) {
                        throw new \Exception("Invalid Google Docs link for: " . $site->site_name);
                    }
                    $orderIndex++;
                }
                
                // Store cart and content links in session for later
                session([
                    'pending_card_payment' => true,
                    'pending_cart' => $cart,
                    'pending_content_links' => $contentLinks,
                    'pending_reference_code' => $referenceCode,
                    'pending_user_id' => $userId
                ]);
                
                $totalAmount = array_sum(array_column($expandedOrders, 'price'));
                
                // Create Stripe Checkout Session
                Stripe::setApiKey(config('services.stripe.secret'));
                
                $checkoutSession = Session::create([
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price_data' => [
                            'currency' => 'eur',
                            'product_data' => [
                                'name' => 'Order Package - ' . count($expandedOrders) . ' item(s)',
                                'description' => 'Order reference: ' . $referenceCode,
                            ],
                            'unit_amount' => $totalAmount * 100,
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    'success_url' => route('advertiser.checkout.process') . '?session_id={CHECKOUT_SESSION_ID}&ref=' . $referenceCode,
                    'cancel_url' => route('advertiser.checkout'),
                    'metadata' => [
                        'type' => 'order_payment',
                        'reference_code' => $referenceCode,
                        'user_id' => (string) $userId,
                        'order_count' => count($expandedOrders)
                    ],
                ]);
                
                Log::info('Stripe session created for card payment (orders not yet created)', [
                    'reference_code' => $referenceCode,
                    'session_id' => $checkoutSession->id,
                    'total_amount' => $totalAmount
                ]);
                
                return response()->json([
                    'success' => true,
                    'requires_payment' => true,
                    'checkout_url' => $checkoutSession->url,
                    'session_id' => $checkoutSession->id,
                    'reference_code' => $referenceCode
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment method'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Order processing failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Create orders immediately for non-card payments
     */
    private function createOrdersImmediately($request, $cart, $paymentMethod, $contentLinks, $referenceCode, $userId)
    {
        try {
            // Expand cart items
            $expandedOrders = [];
            foreach ($cart as $item) {
                for ($i = 0; $i < $item['quantity']; $i++) {
                    $expandedOrders[] = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'copy_number' => $i + 1
                    ];
                }
            }
            
            DB::beginTransaction();
            
            $createdOrders = [];
            $orderIndex = 0;
            
            foreach ($expandedOrders as $orderItem) {
                $site = Site::where('id', $orderItem['id'])->where('active', 1)->first();
                if (!$site) {
                    throw new \Exception("Site not found: " . $orderItem['name']);
                }
                
                $link = null;
                if ($contentLinks && isset($contentLinks[$site->id]) && isset($contentLinks[$site->id][$orderIndex])) {
                    $link = $contentLinks[$site->id][$orderIndex];
                }
                
                $orderNumber = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                
                $paymentStatus = ($paymentMethod === 'wallet') ? 'paid' : 'pending';
                $orderStatus = 'pending'; 
                
                $orderData = [
                    'user_id' => $userId,
                    'order_number' => $orderNumber,
                    'reference_code' => $referenceCode,
                    'subtotal' => $site->price,
                    'tax' => 0,
                    'total_amount' => $site->price,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'status' => $orderStatus
                ];
                
                $order = Order::create($orderData);
                
                OrderItem::create([
                    'order_id' => $order->id,
                    'site_id' => $site->id,
                    'site_name' => $site->site_name,
                    'site_url' => $site->site_url,
                    'price' => $site->price,
                    'content_link' => $link
                ]);
                
                $createdOrders[] = $order;
                $orderIndex++;
            }
            
            // Handle wallet payment deduction
            if ($paymentMethod === 'wallet') {
                $total = array_sum(array_column($expandedOrders, 'price'));
                $wallet = auth()->user()->activeWallet();
                if (!$wallet || $wallet->balance < $total) {
                    throw new \Exception('Insufficient wallet balance.');
                }
                $wallet->balance -= $total;
                $wallet->save();
            }
            
            DB::commit();
            session()->forget('cart');
            
            $orderNumbers = implode(', ', array_column($createdOrders, 'order_number'));
            
            return response()->json([
                'success' => true,
                'message' => count($createdOrders) . ' order(s) placed successfully! Order numbers: ' . $orderNumbers
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Order creation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle Stripe success callback - Create orders AFTER successful payment
     */
    public function handleStripeSuccess(Request $request)
    {
        try {
            $sessionId = $request->query('session_id');
            $referenceCode = $request->query('ref');
            
            Log::info('Stripe success callback received', [
                'session_id' => $sessionId,
                'reference_code' => $referenceCode
            ]);
            
            if (!$sessionId || $sessionId === '{CHECKOUT_SESSION_ID}') {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Invalid payment session.');
            }
            
            if (!$referenceCode) {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Invalid payment callback.');
            }
            
            // Verify payment with Stripe
            Stripe::setApiKey(config('services.stripe.secret'));
            
            try {
                $stripeSession = Session::retrieve($sessionId);
            } catch (\Exception $e) {
                Log::error('Failed to retrieve Stripe session', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage()
                ]);
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Unable to verify payment. Please contact support.');
            }
            
            if ($stripeSession->payment_status !== 'paid') {
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Payment not completed.');
            }
            
            // Check if orders already exist (prevent duplicate)
            $existingOrders = Order::where('reference_code', $referenceCode)->get();
            if ($existingOrders->isNotEmpty()) {
                Log::info('Orders already exist for reference code', ['reference_code' => $referenceCode]);
                session()->forget(['pending_card_payment', 'pending_cart', 'pending_content_links', 'pending_reference_code']);
                return redirect()->route('advertiser.orders')
                    ->with('success', 'Orders have already been processed.');
            }
            
            // Get pending data from session
            $pendingCart = session()->get('pending_cart');
            $pendingContentLinks = session()->get('pending_content_links');
            $pendingUserId = session()->get('pending_user_id') ?? auth()->id();
            
            if (!$pendingCart || !$pendingContentLinks) {
                Log::error('No pending order data found in session');
                return redirect()->route('advertiser.checkout')
                    ->with('error', 'Session expired. Please try again.');
            }
            
            // Expand cart items
            $expandedOrders = [];
            foreach ($pendingCart as $item) {
                for ($i = 0; $i < $item['quantity']; $i++) {
                    $expandedOrders[] = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'copy_number' => $i + 1
                    ];
                }
            }
            
            DB::beginTransaction();
            
            $createdOrders = [];
            $orderIndex = 0;
            
            foreach ($expandedOrders as $orderItem) {
                $site = Site::where('id', $orderItem['id'])->where('active', 1)->first();
                if (!$site) {
                    throw new \Exception("Site not found: " . $orderItem['name']);
                }
                
                if (!isset($pendingContentLinks[$site->id]) || !isset($pendingContentLinks[$site->id][$orderIndex])) {
                    throw new \Exception("Content link required for copy #" . ($orderIndex + 1) . " of: " . $site->site_name);
                }
                
                $link = $pendingContentLinks[$site->id][$orderIndex];
                
                $orderNumber = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                
                $orderData = [
                    'user_id' => $pendingUserId,
                    'order_number' => $orderNumber,
                    'reference_code' => $referenceCode,
                    'subtotal' => $site->price,
                    'tax' => 0,
                    'total_amount' => $site->price,
                    'payment_method' => 'card',
                    'payment_status' => 'paid',  // ← PAID because payment is completed
                    'status' => 'pending',
                    'stripe_session_id' => $stripeSession->id,
                    'stripe_payment_intent_id' => $stripeSession->payment_intent,
                    'stripe_response' => json_encode($stripeSession->toArray()),
                    'paid_at' => now()
                ];
                
                $order = Order::create($orderData);
                
                OrderItem::create([
                    'order_id' => $order->id,
                    'site_id' => $site->id,
                    'site_name' => $site->site_name,
                    'site_url' => $site->site_url,
                    'price' => $site->price,
                    'content_link' => $link
                ]);
                
                $createdOrders[] = $order;
                $orderIndex++;
            }
            
            DB::commit();
            
            // Clear session data
            session()->forget(['pending_card_payment', 'pending_cart', 'pending_content_links', 'pending_reference_code', 'pending_user_id', 'cart']);
            
            $orderNumbers = implode(', ', array_column($createdOrders, 'order_number'));
            
            Log::info('Orders created after successful card payment', [
                'reference_code' => $referenceCode,
                'order_count' => count($createdOrders),
                'order_numbers' => $orderNumbers
            ]);
            
            return redirect()->route('advertiser.orders')
                ->with('success', count($createdOrders) . ' order(s) paid successfully! Order numbers: ' . $orderNumbers);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Stripe success handling failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return redirect()->route('advertiser.checkout')
                ->with('error', 'Payment verification failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get cart count for badge
     */
    public function getCartCount(Request $request)
    {
        $cart = session()->get('cart', []);
        $count = array_sum(array_column($cart, 'quantity'));
        return response()->json(['count' => $count]);
    }
    
    /**
     * Orders page
     */
    public function orders()
    {
        return view('advertiser.orders');
    }
    
    /**
     * Get orders list (AJAX)
     */
    public function getOrders(Request $request)
    {
        try {
            $userId = auth()->id();
            
            $query = Order::where('user_id', $userId)->with('items');
            
            // Search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                      ->orWhereHas('items', function($sub) use ($search) {
                          $sub->where('site_name', 'like', "%{$search}%");
                      });
                });
            }
            
            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            
            // Payment status filter
            if ($request->filled('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }
            
            // Payment method filter
            if ($request->filled('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }
            
            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            $orders = $query->orderBy('created_at', 'desc')->paginate(20);
            
            return response()->json([
                'success' => true,
                'orders' => $orders->items(),
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
            Log::error('Error fetching orders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders'
            ]);
        }
    }
    
    /**
     * Get single order details (AJAX)
     */
    public function getOrder($id)
    {
        try {
            $userId = auth()->id();
            
            $order = Order::where('user_id', $userId)
                ->with('items')
                ->find($id);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ]);
            }
            
            return response()->json([
                'success' => true,
                'order' => $order
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details'
            ]);
        }
    }
}