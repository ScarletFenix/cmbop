<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItemDispute;
use App\Services\Orders\AdminOrderStatusOverride;
use App\Services\Orders\OrderClawbackService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index()
    {
        return view('admin.orders.index');
    }

    public function data(Request $request)
    {
        $query = Order::with(['user', 'items.site.publisher'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->string('payment_status')->toString());
        }

        if ($request->input('dispute') === 'open' && OrderItemDispute::tableAvailable()) {
            $query->whereHas('disputes', fn ($q) => $q->where('status', OrderItemDispute::STATUS_OPEN));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to')->toString());
        }

        $perPage = max(1, min(100, (int) $request->get('per_page', 20)));
        $orders = $query->paginate($perPage);

        $data = $orders->getCollection()->map(function (Order $order) {
            $item = $order->items->first();
            $site = $item?->site;
            $publisher = $site?->publisher;

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'reference_code' => $order->reference_code,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'total_amount' => (float) $order->total_amount,
                'created_at' => optional($order->created_at)?->toIso8601String(),
                'created_at_human' => optional($order->created_at)?->format('M j, Y g:i A'),
                'advertiser' => $order->user ? [
                    'id' => $order->user->id,
                    'name' => $order->user->name,
                    'email' => $order->user->email,
                ] : null,
                'site_name' => $item?->site_name ?: ($site?->site_name),
                'publisher_name' => $publisher?->name,
                'live_url' => $item?->live_url,
                'modification_requested' => $item?->modification_requested,
                'url' => route('admin.orders.show', $order->id),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show($id)
    {
        // Heal a skipped migration before reading, so disputes come back rather
        // than staying invisible on the screen built to manage them.
        OrderItemDispute::ensureTable();

        $order = Order::with(array_merge([
            'user',
            'items.site.publisher',
            'chatMessages.user',
        ], OrderItemDispute::eagerPaths([
            'items.disputes.opener',
            'items.disputes.resolver',
        ])))->findOrFail($id);

        $activities = OrderActivity::where('order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (OrderActivity $a) => $a->toApiArray())
            ->values();

        $item = $order->items->first();
        $disputes = $item && OrderItemDispute::tableAvailable()
            ? $item->disputes->sortByDesc('id')->values()
            : collect();
        $openDispute = $disputes->first(fn (OrderItemDispute $d) => $d->isOpen());
        $canOpenDispute = app(OrderClawbackService::class)->canOpenDispute($order, $item, asAdmin: true);

        $override = app(AdminOrderStatusOverride::class);

        return view('admin.orders.show', [
            'order' => $order,
            'activities' => $activities,
            'messages' => $order->chatMessages,
            'disputes' => $disputes,
            'openDispute' => $openDispute,
            'canOpenDispute' => $canOpenDispute,
            'statusTargets' => $override->availableFor($order),
            'canOverrideStatus' => $override->isOverridable($order),
        ]);
    }

    /**
     * Move a running order between stages when it is stuck on the wrong one.
     * Settling an order stays with approval and refunds — see the service.
     */
    public function updateStatus(Request $request, $id, AdminOrderStatusOverride $override)
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $order = Order::with('items')->findOrFail($id);

        try {
            $override->apply($order, $data['status'], $request->user(), $data['reason']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Order '.$order->order_number.' moved to '.$data['status'].'.');
    }
}
