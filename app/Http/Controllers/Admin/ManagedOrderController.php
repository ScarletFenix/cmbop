<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\AdminManagedOrderCreateService;
use App\Services\Orders\ManagedFulfilmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ManagedOrderController extends Controller
{
    public function __construct(
        private ManagedFulfilmentService $managed,
        private AdminManagedOrderCreateService $createService,
    ) {}

    public function index(Request $request): View
    {
        $status = trim((string) $request->input('status', ''));
        $assigneeId = $request->integer('assignee_id') ?: null;

        $items = $this->managed->deskQuery(
            $status !== '' ? $status : null,
            $assigneeId
        )->paginate(30)->withQueryString();

        return view('admin.managed-orders.index', [
            'items' => $items,
            'status' => $status,
            'assigneeId' => $assigneeId,
            'statuses' => ManagedFulfilmentService::internalStatuses(),
            'assignees' => $this->managed->staffAssignees(),
        ]);
    }

    public function create(Request $request): View
    {
        $advertiserSearch = trim((string) $request->input('advertiser_q', ''));
        $siteSearch = trim((string) $request->input('site_q', ''));

        return view('admin.managed-orders.create', [
            'advertisers' => $this->createService->advertiserOptions($advertiserSearch !== '' ? $advertiserSearch : null),
            'sites' => $this->createService->siteOptions($siteSearch !== '' ? $siteSearch : null),
            'assignees' => $this->managed->staffAssignees(),
            'advertiserSearch' => $advertiserSearch,
            'siteSearch' => $siteSearch,
        ]);
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'advertiser_id' => 'required|integer',
            'site_id' => 'required|integer',
            'anchor_text' => 'required|string|max:500',
            'target_url' => 'required|string|max:2000',
            'content_source' => 'required|in:buyer,slb',
            'content_url' => 'nullable|string|max:2000',
            'managed_assignee_id' => 'nullable|integer',
            'managed_notes' => 'nullable|string|max:5000',
        ]);

        try {
            $preview = $this->createService->preview($data);
        } catch (ValidationException $e) {
            return redirect()
                ->route('admin.managed-orders.create')
                ->withErrors($e->errors())
                ->withInput();
        }

        $idempotencyKey = $this->createService->newIdempotencyKey();

        return view('admin.managed-orders.confirm', [
            'preview' => $preview,
            'form' => $data,
            'idempotencyKey' => $idempotencyKey,
            'assignees' => $this->managed->staffAssignees(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'advertiser_id' => 'required|integer',
            'site_id' => 'required|integer',
            'anchor_text' => 'required|string|max:500',
            'target_url' => 'required|string|max:2000',
            'content_source' => 'required|in:buyer,slb',
            'content_url' => 'nullable|string|max:2000',
            'managed_assignee_id' => 'nullable|integer',
            'managed_notes' => 'nullable|string|max:5000',
            'idempotency_key' => 'required|string|min:8|max:64',
            'confirm_charge' => 'accepted',
        ]);

        try {
            $order = $this->createService->create($request->user(), $data);
        } catch (ValidationException $e) {
            return redirect()
                ->route('admin.managed-orders.create')
                ->withErrors($e->errors())
                ->withInput($request->except(['idempotency_key', 'confirm_charge']));
        }

        $item = $order->items->first();
        if (! $item) {
            return redirect()
                ->route('admin.managed-orders.index')
                ->with('success', 'Order #'.($order->order_number ?: $order->id).' created.');
        }

        return redirect()
            ->route('admin.managed-orders.show', $item)
            ->with('success', 'Order #'.($order->order_number ?: $order->id).' created · Managed by SLB. Advertiser wallet charged once.');
    }

    public function show(OrderItem $orderItem): View
    {
        abort_unless($this->managed->isManaged($orderItem), 404);

        $orderItem->load(['order.user', 'site.publisher', 'managedAssignee', 'managedCompletedBy']);

        return view('admin.managed-orders.show', [
            'item' => $orderItem,
            'order' => $orderItem->order,
            'statuses' => ManagedFulfilmentService::internalStatuses(),
            'assignees' => $this->managed->staffAssignees(),
        ]);
    }

    public function mark(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'order_item_id' => 'nullable|integer',
            'managed_assignee_id' => 'nullable|integer',
            'managed_notes' => 'nullable|string|max:5000',
        ]);

        $order->load('items');
        $items = $order->items;
        if (! empty($data['order_item_id'])) {
            $items = $items->where('id', (int) $data['order_item_id'])->values();
        }
        if ($items->isEmpty()) {
            return back()->withErrors(['fulfilment' => 'No placement found to mark.']);
        }

        $marked = 0;
        foreach ($items as $item) {
            if ($item->isManagedBySlb()) {
                continue;
            }
            $this->managed->markManaged(
                $item,
                $request->user(),
                isset($data['managed_assignee_id']) ? (int) $data['managed_assignee_id'] : null,
                $data['managed_notes'] ?? null,
                $marked === 0,
            );
            $marked++;
        }
        if ($marked === 0) {
            return back()->withErrors(['fulfilment' => 'All selected placements are already Managed by SLB.']);
        }

        return redirect()
            ->route('admin.managed-orders.index')
            ->with('success', 'Marked Managed by SLB. Publisher Tasks and payouts are isolated for this placement.');
    }

    public function update(Request $request, OrderItem $orderItem): RedirectResponse
    {
        abort_unless($this->managed->isManaged($orderItem), 404);

        $data = $request->validate([
            'managed_assignee_id' => 'nullable|integer',
            'managed_internal_status' => 'nullable|string|max:40',
            'managed_notes' => 'nullable|string|max:5000',
        ]);

        $this->managed->updateDesk(
            $orderItem,
            $request->user(),
            array_key_exists('managed_assignee_id', $data)
                ? ($data['managed_assignee_id'] !== null ? (int) $data['managed_assignee_id'] : null)
                : null,
            $data['managed_internal_status'] ?? null,
            array_key_exists('managed_notes', $data) ? (string) ($data['managed_notes'] ?? '') : null,
            [
                'assignee' => array_key_exists('managed_assignee_id', $data),
                'status' => array_key_exists('managed_internal_status', $data),
                'notes' => array_key_exists('managed_notes', $data),
            ],
        );

        return back()->with('success', 'Managed desk row updated.');
    }

    public function attachLiveUrl(Request $request, OrderItem $orderItem): RedirectResponse
    {
        abort_unless($this->managed->isManaged($orderItem), 404);

        $data = $request->validate([
            'live_url' => 'required|string|max:2000',
        ]);

        $this->managed->attachLiveUrl($orderItem, $data['live_url'], $request->user());

        return back()->with('success', 'Live URL saved. The advertiser was notified.');
    }

    public function complete(Request $request, OrderItem $orderItem): RedirectResponse
    {
        abort_unless($this->managed->isManaged($orderItem), 404);

        $data = $request->validate([
            'live_url' => 'nullable|string|max:2000',
        ]);

        $this->managed->complete(
            $orderItem,
            $request->user(),
            $data['live_url'] ?? null,
        );

        return redirect()
            ->route('admin.managed-orders.show', $orderItem)
            ->with('success', 'Order completed · Managed by SLB. Publisher wallet was not credited.');
    }
}
