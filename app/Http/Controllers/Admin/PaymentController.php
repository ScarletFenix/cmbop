<?php

// app/Http/Controllers/Admin/PaymentController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrderPaymentConfirmed;
use App\Models\ContentSubmission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ActivityLogger;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\Billing\BillingDocumentService;
use App\Services\InAppNotificationService;
use App\Services\OrderPaymentService;
use App\Services\Orders\OrderRefundService;
use App\Support\BillingCustomerMailSuppressor;
use App\Support\OrderLifecycleMailSuppressor;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PaymentController extends Controller
{
    /**
     * Display payments list page
     */
    public function index()
    {
        return view('admin.payments');
    }

    /**
     * Get payments data for DataTable (AJAX)
     */
    public function getPaymentsData(Request $request)
    {
        try {
            $query = Order::with('user')->orderBy('created_at', 'desc');

            // Search filter. Arrays (search[]) must not be interpolated into LIKE.
            $search = search_text($request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('reference_code', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($sub) use ($search) {
                            $sub->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            // Payment status filter. "unpaid" is the ops queue, not an enum value.
            $paymentStatus = is_string($request->input('payment_status')) ? $request->input('payment_status') : '';
            if ($paymentStatus === 'unpaid') {
                $query->unpaidOps();
            } elseif ($paymentStatus !== '') {
                $query->where('payment_status', $paymentStatus);
            }

            $paymentMethod = is_string($request->input('payment_method')) ? $request->input('payment_method') : '';
            if ($paymentMethod !== '') {
                $query->where('payment_method', $paymentMethod);
            }

            $orderStatus = is_string($request->input('status')) ? $request->input('status') : '';
            if ($orderStatus !== '') {
                $query->where('status', $orderStatus);
            }

            $dates = validator(
                [
                    'date_from' => is_string($request->input('date_from')) ? $request->input('date_from') : null,
                    'date_to' => is_string($request->input('date_to')) ? $request->input('date_to') : null,
                ],
                [
                    'date_from' => 'nullable|date',
                    'date_to' => 'nullable|date|after_or_equal:date_from',
                ]
            )->valid();
            if (! empty($dates['date_from'])) {
                $query->whereDate('created_at', '>=', $dates['date_from']);
            }
            if (! empty($dates['date_to'])) {
                $query->whereDate('created_at', '<=', $dates['date_to']);
            }

            $perPage = $request->get('per_page', 20);
            $orders = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $orders->items(),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching payments: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to fetch payments. Please try again.'),
            ], 500);
        }
    }

    /**
     * Show single payment details
     */
    public function show($id)
    {
        try {
            $order = Order::with(['user', 'items.site'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $order,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found',
            ], 404);
        }
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        // jQuery form posts send_notification as "true"/"false". Laravel's
        // boolean rule only allows true/false/0/1/"0"/"1", so normalize first.
        $this->mergeJqueryBoolean($request, 'send_notification');

        // Outside the try: the catch-all would turn a ValidationException into a 500.
        $request->validate([
            'payment_status' => 'required|in:pending,paid,failed,refunded',
            'notes' => 'nullable|string',
            'send_notification' => 'sometimes|boolean',
        ]);

        // Omitted (API / existing clients) still notifies. The Order Payments
        // checkbox posts true/false and must be honoured.
        $sendNotification = $request->has('send_notification')
            ? $request->boolean('send_notification')
            : true;

        $billingSuppressor = app(BillingCustomerMailSuppressor::class);

        try {
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->suppress((int) $id, ['advertiser']);
                $billingSuppressor->enable();
            }

            DB::beginTransaction();

            $order = Order::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();

            $oldStatus = $order->payment_status;
            $newStatus = (string) $request->payment_status;

            if ($newStatus === 'paid' && $oldStatus !== 'paid') {
                if (in_array((string) $order->status, ['cancelled', 'completed'], true)
                    || $oldStatus === 'refunded') {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'This order cannot be marked paid. Cancelled, completed, or refunded payments have to stay settled.',
                    ], 422);
                }
            }

            if ($oldStatus === 'paid' && $newStatus === 'pending') {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'A paid payment cannot be moved back to pending. Mark it failed or refunded instead.',
                ], 422);
            }

            $order->payment_status = $newStatus;

            if ($request->payment_status === 'paid' && ! $order->paid_at) {
                $order->paid_at = now();
            }

            $refundAmount = 0.0;
            if ($request->payment_status === 'refunded' && $oldStatus === 'paid') {
                if ($order->status === 'completed') {
                    DB::rollBack();
                    if (! $sendNotification) {
                        app(OrderLifecycleMailSuppressor::class)->forget((int) $id);
                    }

                    return response()->json([
                        'success' => false,
                        'message' => 'Completed orders cannot be refunded here. Use a dispute clawback so the publisher payout is reversed first.',
                    ], 422);
                }

                $refundAmount = $this->creditAdvertiserRefund($order);
                if ($order->status !== 'cancelled') {
                    $order->status = 'cancelled';
                }
                ContentSubmission::releaseAllForOrder((int) $order->id);
            }

            if ($request->payment_status === 'failed' && $oldStatus === 'paid') {
                if ($order->payment_method === 'wallet') {
                    $refundAmount = $this->releaseWalletHoldOnAdminFailed($order);
                } elseif (! in_array((string) $order->status, ['cancelled', 'completed'], true)) {
                    // Collected card / bank / Wise: credit the advertiser wallet
                    // the same way Refunded does. Failed used to cancel the
                    // placement and release the article with €0 in-app credit.
                    $refundAmount = $this->creditAdvertiserRefund($order);
                    $order->status = 'cancelled';
                }

                if ($order->status === 'cancelled') {
                    ContentSubmission::releaseAllForOrder((int) $order->id);
                }
            }

            $order->save();

            Log::info('Payment status updated by admin', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $request->payment_status,
                'admin_id' => auth()->id(),
            ]);

            // Send email notification to user when payment is marked as paid.
            // Do not consume checkout bonus here — Stripe mark-paid also leaves
            // bonus_reserved until approve/reject so a later refund cannot mint
            // promo as withdrawable cash.
            if ($request->payment_status === 'paid' && $oldStatus !== 'paid') {
                if ($sendNotification) {
                    $this->sendPaymentConfirmationEmail($order);
                }
            }

            // Unpaid failure: release this line's leftover checkout bonus.
            // Paid failures already restored promo via creditAdvertiserRefund /
            // releaseWalletHoldOnAdminFailed — do not dump the sibling share.
            if ($request->payment_status === 'failed' && $oldStatus !== 'failed' && $oldStatus !== 'paid') {
                $this->refundReservedCheckoutBonus($order);
            }

            DB::commit();

            $fresh = $order->fresh(['items']);
            $notifications = app(InAppNotificationService::class);

            if ($request->payment_status === 'paid' && $oldStatus !== 'paid') {
                app(OrderPaymentService::class)->notifyPublishersOfPaidOrders([$fresh]);
                if ($fresh->user) {
                    try {
                        app(SpendBudgetService::class)->evaluate($fresh->user);
                    } catch (\Throwable $e) {
                        Log::warning('Spend budget evaluate after admin mark-paid failed: '.$e->getMessage());
                    }
                }
            }

            if ($sendNotification && $request->payment_status === 'failed' && $oldStatus !== 'failed') {
                $notifications->notifyPaymentFailed([$fresh], $request->notes);
                if ($refundAmount > 0) {
                    $notifications->notifyRefundCredited(
                        $fresh,
                        $refundAmount,
                        $request->notes ?: 'Admin marked payment failed'
                    );
                }
            }

            if ($sendNotification && $request->payment_status === 'refunded' && $oldStatus !== 'refunded' && $refundAmount > 0) {
                $notifications->notifyRefundCredited(
                    $fresh,
                    $refundAmount,
                    $request->notes ?: 'Admin refund'
                );
            }

            ActivityLogger::log(
                'payment.status_updated',
                auth()->user()->name.' set payment for order '.$order->order_number.' to '.$request->payment_status,
                $order,
                ['from' => $oldStatus, 'to' => $request->payment_status],
                $order->order_number
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'payment_status' => $order->payment_status,
                    'paid_at' => $order->paid_at,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            if (! $sendNotification) {
                app(OrderLifecycleMailSuppressor::class)->forget((int) $id);
            }
            Log::error('Error updating payment status: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to update payment status. Please try again.'),
            ], 500);
        } finally {
            if (! $sendNotification) {
                $billingSuppressor->disable();
            }
        }
    }

    /**
     * Send payment confirmation email to user.
     * Prefer the PDF tax-invoice mail (BillingDocumentService). Skip the legacy
     * OrderPaymentConfirmed when that invoice mail already went out — otherwise
     * admins marking paid trigger a double email.
     */
    private function sendPaymentConfirmationEmail($order)
    {
        try {
            $order = $order->fresh(['user', 'items']) ?: $order;

            $invoice = Invoice::query()
                ->where('order_id', $order->id)
                ->where('type', Invoice::TYPE_TAX_INVOICE)
                ->where('status', '!=', Invoice::STATUS_CANCELLED)
                ->latest('id')
                ->first();

            if (! $invoice) {
                $invoice = app(BillingDocumentService::class)->handlePaymentPaid($order);
            }

            if ($invoice && $invoice->emailed_at) {
                Log::info('Skipping legacy payment confirmation — PDF invoice already emailed', [
                    'order_id' => $order->id,
                    'invoice_id' => $invoice->id,
                ]);

                return;
            }

            if ($invoice && ! $invoice->emailed_at) {
                app(BillingDocumentService::class)->resendInvoiceEmail($invoice);

                return;
            }

            $user = $order->user;

            if ($user && $user->email) {
                Mail::to($user->email)->send(new OrderPaymentConfirmed($order));
                Log::info('Payment confirmation email sent to user', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'user_email' => $user->email,
                ]);
            } else {
                Log::warning('Cannot send payment confirmation - no user email', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send payment confirmation email: '.$e->getMessage());
        }
    }

    private function refundReservedCheckoutBonus(Order $order): void
    {
        app(OrderRefundService::class)->releaseReservedCheckoutBonus($order);
    }

    /**
     * Paid wallet orders keep cash/bonus in reserved_balance until approve/reject.
     * Admin "failed" used to flip payment_status only, leaving the hold locked
     * and Approve still able to pay the publisher from that reserved bucket.
     */
    private function releaseWalletHoldOnAdminFailed(Order $order): float
    {
        if (in_array((string) $order->status, ['completed', 'cancelled'], true)) {
            return 0.0;
        }

        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
            }

            return 0.0;
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if (! $advertiserRoleId) {
            throw new \RuntimeException('Advertiser role not configured');
        }

        $wallet = Wallet::lockOrCreateForRole($order->user_id, $advertiserRoleId);
        $reservedBefore = round((float) $wallet->reserved_balance, 2);
        if ($reservedBefore <= 0) {
            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
            }

            return 0.0;
        }

        app(OrderRefundService::class)->refundToAdvertiser(
            $order,
            $amount,
            'Admin marked payment failed'
        );
        $wallet->refresh();
        $refunded = max(0, round($reservedBefore - (float) $wallet->reserved_balance, 2));

        if ($order->status !== 'cancelled') {
            $order->status = 'cancelled';
        }

        return $refunded;
    }

    /**
     * Credit the advertiser wallet when admin marks a paid order as refunded.
     * Mirrors publisher reject refund behaviour.
     */
    private function creditAdvertiserRefund(Order $order): float
    {
        $order->loadMissing('items');
        $amount = app(OrderRefundService::class)
            ->resolveLineRefundAmount(
                $order,
                (float) ($order->items->sum('price') ?: $order->total_amount)
            );
        if ($amount <= 0) {
            return 0.0;
        }

        app(OrderRefundService::class)->refundToAdvertiser($order, $amount, 'Admin refund');

        return $amount;
    }

    /**
     * Map jQuery/form truthy strings onto real booleans before the boolean rule.
     */
    private function mergeJqueryBoolean(Request $request, string $key): void
    {
        if (! $request->exists($key) || ! is_string($request->input($key))) {
            return;
        }

        $raw = strtolower(trim((string) $request->input($key)));
        if (! in_array($raw, ['true', 'false', 'on', 'off', 'yes', 'no'], true)) {
            return;
        }

        $request->merge([
            $key => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
        ]);
    }
}
