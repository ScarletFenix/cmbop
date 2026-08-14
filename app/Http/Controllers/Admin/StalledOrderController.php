<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PublisherAcceptNudge;
use App\Mail\PublisherPublishNudge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Services\Reminders\OrderDeadline;
use App\Services\Reminders\StalledOrderQueue;
use App\Support\UserFacingError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Admin view of orders the automated reminders could not move, plus a manual
 * "chase them now" that does not wait for the next scheduled run.
 */
class StalledOrderController extends Controller
{
    public function index(StalledOrderQueue $queue): JsonResponse
    {
        try {
            $items = $queue->items(25);

            return response()->json([
                'success' => true,
                'items' => $items,
                'count' => $items->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Stalled order queue failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to load stalled orders'),
            ], 500);
        }
    }

    /**
     * Send the publisher a reminder right now.
     *
     * Does not advance the cadence stage: an admin chasing by hand should not
     * consume the escalation the scheduled command is working towards, or a
     * couple of manual chases would silently exhaust it.
     *
     * Track matches StalledOrderQueue: no accepted_at → accept nudge; otherwise
     * publish nudge. A "Not accepted" row must not tell the publisher to submit
     * a live URL.
     */
    public function remindPublisher(
        int $orderItem,
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
        OrderDeadline $deadlines,
    ): JsonResponse {
        try {
            $item = OrderItem::with(['order', 'site'])->findOrFail($orderItem);
            $order = $item->order;
            $site = $item->site;
            $publisher = $site?->publisher_id ? User::find($site->publisher_id) : null;

            if (! $order || ! $publisher?->email) {
                return response()->json([
                    'success' => false,
                    'message' => 'No publisher email on file for this order.',
                ], 422);
            }

            $track = $item->accepted_at === null ? 'accept' : 'publish';

            if ($track === 'accept') {
                $hoursOverdue = $this->sendAcceptReminder($mailer, $bells, $item, $order, $site, $publisher);
            } else {
                $hoursOverdue = $this->sendPublishReminder($mailer, $bells, $deadlines, $item, $order, $site, $publisher);
            }

            ActivityLogger::log(
                'order.publisher_reminded',
                'Sent a manual '.$track.' reminder to '.$publisher->email,
                $order,
                [
                    'order_item_id' => $item->id,
                    'publisher_id' => $publisher->id,
                    'hours_overdue' => $hoursOverdue,
                    'track' => $track,
                ],
                '#'.$order->order_number
            );

            return response()->json([
                'success' => true,
                'message' => 'Reminder sent to '.$publisher->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Manual publisher reminder failed', [
                'order_item_id' => $orderItem,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, 'Failed to send the reminder'),
            ], 500);
        }
    }

    private function sendAcceptReminder(
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
        OrderItem $item,
        Order $order,
        ?Site $site,
        User $publisher,
    ): int {
        $anchor = $order->paid_at ?? $order->created_at;
        $hoursWaiting = $anchor ? max(0, (int) $anchor->diffInHours(now())) : 0;
        $stage = max(1, (int) $item->accept_nudge_stage);

        $mailable = new PublisherAcceptNudge(
            $publisher,
            $order,
            $item,
            $site,
            $stage,
            $hoursWaiting
        );
        // Timestamped so a manual chase is never deduped against the
        // scheduled one for the same stage.
        $mailable->dedupeKey = 'publisher_accept_nudge:manual:'.$item->id.':'.now()->timestamp;

        $mailer->sendReminder($publisher, $mailable);

        try {
            $bells->notifyPublisherAcceptNudge($order, $item, $publisher, $stage);
        } catch (\Throwable $e) {
            Log::warning('Manual reminder bell failed', ['error' => $e->getMessage()]);
        }

        return $hoursWaiting;
    }

    private function sendPublishReminder(
        EmailNotificationService $mailer,
        InAppNotificationService $bells,
        OrderDeadline $deadlines,
        OrderItem $item,
        Order $order,
        ?Site $site,
        User $publisher,
    ): int {
        $deadline = $deadlines->for($item, $order, $site);
        $hoursOverdue = $deadline ? max(0, (int) $deadline->diffInHours(now(), false)) : 0;
        $stage = max(1, (int) $item->publish_nudge_stage);

        $mailer->sendReminder($publisher, new PublisherPublishNudge(
            $publisher,
            collect([[
                'order_id' => (int) $order->id,
                'order_number' => (string) $order->order_number,
                'site_name' => (string) ($site->site_name ?: $item->site_name ?: 'your site'),
                'due_at' => $deadline ?? now(),
                'hours_overdue' => $hoursOverdue,
                'overdue_label' => $hoursOverdue >= 48
                    ? ((int) round($hoursOverdue / 24)).' days late'
                    : $hoursOverdue.'h late',
                'promised' => (string) ($site->turnaround_time ?: 'listed'),
                'payout' => (float) $item->publisherPayoutAmount(),
            ]]),
            $stage,
            // Timestamped so a manual chase is never deduped against the
            // scheduled one for the same stage.
            'manual:'.$item->id.':'.now()->timestamp
        ));

        try {
            $bells->notifyPublisherPublishNudge($order, $item, $publisher, $stage, $hoursOverdue);
        } catch (\Throwable $e) {
            Log::warning('Manual reminder bell failed', ['error' => $e->getMessage()]);
        }

        return $hoursOverdue;
    }
}
