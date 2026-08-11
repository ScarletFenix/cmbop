<?php

namespace App\Services\Orders;

use App\Mail\LiveUrlSubmitted;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Services\LiveUrlHealthChecker;
use App\Support\OrderLifecycleMailSuppressor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Hands an order item back to the advertiser for review.
 *
 * Resubmitting a corrected URL and reporting a revision as fixed differ only in
 * whether the address changed, so they share the transition rather than letting
 * two near-identical copies drift apart.
 */
class ReviewHandoffService
{
    public function __construct(
        private LiveUrlHealthChecker $healthChecker,
        private InAppNotificationService $notifications,
        private OrderLifecycleMailSuppressor $lifecycleSuppressor,
    ) {}

    /**
     * @return array{ok: bool, status: ?int, message: string, checked_at: Carbon}
     */
    public function handBack(OrderItem $item, Site $site, string $liveUrl, ?string $chatMessage = null): array
    {
        // Check before opening the transaction: the request can be slow and the
        // article may have been edited since it was last seen.
        $health = $this->healthChecker->check($liveUrl);

        $orderId = (int) $item->order_id;

        // LiveUrlSubmitted covers the advertiser; skip generic status mail for them.
        // Query-builder status updates do not fire Eloquent events, so always clear
        // the suppressor in finally (lifecycle pull alone is not enough here).
        $this->lifecycleSuppressor->suppress($orderId, ['advertiser']);

        try {
            DB::transaction(function () use ($item, $liveUrl, $health) {
                $item->update($this->itemPayload($liveUrl, $health));

                Order::where('id', $item->order_id)->update(['status' => 'review']);
            });

            $order = Order::with(['user', 'items'])->find($item->order_id);
            $item->refresh();

            if (! $order) {
                return $health;
            }

            // Dedicated LiveUrlSubmitted (+ bell) covers the advertiser for this handoff.
            if ($chatMessage !== null) {
                $this->postChatMessage($order, $chatMessage);
            }

            $this->emailAdvertiser($order, $item, $site, $liveUrl);
            $this->notifyAdvertiser($order, $item, $site, $liveUrl);

            return $health;
        } finally {
            $this->lifecycleSuppressor->forget($orderId);
        }
    }

    /**
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    private function itemPayload(string $liveUrl, array $health): array
    {
        $payload = [
            'live_url' => $liveUrl,
            // Restarting the clock gives the advertiser a full review window
            // every time the publisher hands the article back.
            'live_url_submitted_at' => now(),
            'modification_requested' => 'no',
            'modification_requested_at' => null,
            'auto_approve_triggered' => false,
        ];

        if (Schema::hasColumn('order_items', 'auto_approve_reminder_sent_at')) {
            $payload['auto_approve_reminder_sent_at'] = null;
        }

        if (Schema::hasColumn('order_items', 'live_url_check_ok')) {
            $payload['live_url_check_ok'] = $health['ok'];
            $payload['live_url_http_status'] = $health['status'];
            $payload['live_url_checked_at'] = $health['checked_at'];
        }

        return $payload;
    }

    private function postChatMessage(Order $order, string $message): void
    {
        try {
            OrderChatMessage::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'sender_type' => 'publisher',
                'message' => $message,
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create review handoff chat message', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function emailAdvertiser(Order $order, OrderItem $item, Site $site, string $liveUrl): void
    {
        try {
            $advertiser = $order->user ?: User::find($order->user_id);

            if ($advertiser?->email) {
                Mail::to($advertiser->email)->send(new LiveUrlSubmitted($order, $item, $site, $liveUrl));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send review handoff email', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdvertiser(Order $order, OrderItem $item, Site $site, string $liveUrl): void
    {
        try {
            $this->notifications->notifyLiveUrlSubmitted($order, $item, $site, $liveUrl);
        } catch (\Throwable $e) {
            Log::warning('Failed to create review handoff in-app notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
