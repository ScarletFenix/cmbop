<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\AdminManagedOrderCreateService;
use App\Services\Orders\ManagedFulfilmentService;
use Illuminate\Http\JsonResponse;
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
        return view('admin.managed-orders.create', [
            'assignees' => $this->managed->staffAssignees(),
            'advertiserSearchUrl' => route('admin.managed-orders.advertisers-search'),
            'siteSearchUrl' => route('admin.managed-orders.sites-search'),
            'librarySearchUrl' => route('admin.managed-orders.library-search'),
            'quoteUrl' => route('admin.managed-orders.quote'),
            'slbWritingFee' => \App\Services\Marketplace\WritingFeeService::SLB_FLAT_EUR,
        ]);
    }

    public function searchAdvertisers(Request $request): JsonResponse
    {
        $q = search_text($request->query('q'));
        $selected = (int) $request->query('selected', 0) ?: null;

        return response()->json([
            'options' => $this->createService->searchAdvertisers($q !== '' ? $q : null, $selected),
        ]);
    }

    public function searchSites(Request $request): JsonResponse
    {
        $q = search_text($request->query('q'));
        $selected = (int) $request->query('selected', 0) ?: null;

        return response()->json([
            'options' => $this->createService->searchSites($q !== '' ? $q : null, $selected),
        ]);
    }

    public function searchLibrary(Request $request): JsonResponse
    {
        $advertiserId = (int) $request->query('advertiser_id', 0);
        $q = search_text($request->query('q'));
        $selected = (int) $request->query('selected', 0) ?: null;

        return response()->json([
            'options' => $this->createService->searchLibraryArticles(
                $advertiserId,
                $q !== '' ? $q : null,
                $selected
            ),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        try {
            $data = $this->validatedCreatePayload($request, false);
            $quote = $this->createService->quote($data);

            return response()->json(['success' => true, 'quote' => $quote]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Could not price this order.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        try {
            $data = $this->validatedCreatePayload($request, false);
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
            'quote' => $preview['quote'],
            'form' => $data,
            'idempotencyKey' => $idempotencyKey,
            'assignees' => $this->managed->staffAssignees(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $data = $this->validatedCreatePayload($request, true);
            $order = $this->createService->create($request->user(), $data);
        } catch (ValidationException $e) {
            return redirect()
                ->route('admin.managed-orders.create')
                ->withErrors($e->errors())
                ->withInput($request->except(['idempotency_key', 'confirm_charge']));
        }

        $item = $order->items->first();
        $count = Order::query()
            ->where('reference_code', $order->reference_code)
            ->where('payment_status', 'paid')
            ->count();
        $msg = 'Order #'.($order->order_number ?: $order->id).' created · Managed by SLB. Advertiser wallet charged once.';
        if ($count > 1) {
            $msg = $count.' placements created under ref '.$order->reference_code.'. Advertiser wallet charged once.';
        }

        if (! $item) {
            return redirect()->route('admin.managed-orders.index')->with('success', $msg);
        }

        return redirect()
            ->route('admin.managed-orders.show', $item)
            ->with('success', $msg);
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
            'managed_external_reference' => 'nullable|string|max:500',
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

        if (array_key_exists('managed_external_reference', $data)
            && \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'managed_external_reference')) {
            $orderItem->forceFill([
                'managed_external_reference' => filled($data['managed_external_reference'])
                    ? trim((string) $data['managed_external_reference'])
                    : null,
            ])->save();
        }

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

    /**
     * @return array<string, mixed>
     */
    private function validatedCreatePayload(Request $request, bool $forStore): array
    {
        $rules = [
            'advertiser_id' => 'required|integer',
            'managed_assignee_id' => 'nullable|integer',
            'managed_notes' => 'nullable|string|max:5000',
            'managed_external_reference' => 'nullable|string|max:500',
            'sites' => 'required|array|min:1|max:20',
            'sites.*.site_id' => 'required|integer',
            'sites.*.quantity' => 'nullable|integer|min:1|max:10',
            'sites.*.content_source' => 'required|in:buyer,slb,library',
            'sites.*.content_url' => 'nullable|string|max:2000',
            'sites.*.content_submission_id' => 'nullable|integer',
            'sites.*.writing_brief_notes' => 'nullable|string|max:5000',
            'sites.*.links' => 'required|array|min:1|max:3',
            'sites.*.links.*.anchor' => 'nullable|string|max:500',
            'sites.*.links.*.url' => 'nullable|string|max:2000',
        ];

        // Legacy single-site fields still accepted.
        if (! $request->has('sites') && $request->filled('site_id')) {
            $rules = [
                'advertiser_id' => 'required|integer',
                'site_id' => 'required|integer',
                'anchor_text' => 'required|string|max:500',
                'target_url' => 'required|string|max:2000',
                'content_source' => 'required|in:buyer,slb,library',
                'content_url' => 'nullable|string|max:2000',
                'content_submission_id' => 'nullable|integer',
                'writing_brief_notes' => 'nullable|string|max:5000',
                'managed_assignee_id' => 'nullable|integer',
                'managed_notes' => 'nullable|string|max:5000',
                'managed_external_reference' => 'nullable|string|max:500',
            ];
        }

        if ($forStore) {
            $rules['idempotency_key'] = 'required|string|min:8|max:64';
            $rules['confirm_charge'] = 'accepted';
        }

        return $request->validate($rules);
    }
}
