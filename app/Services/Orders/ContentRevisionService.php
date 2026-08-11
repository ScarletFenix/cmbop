<?php

namespace App\Services\Orders;

use App\Mail\ContentRevisionFulfilled;
use App\Mail\ContentRevisionRequested;
use App\Models\ContentSubmission;
use App\Models\Order;
use App\Models\OrderChatMessage;
use App\Models\OrderItem;
use App\Models\Site;
use App\Models\User;
use App\Services\InAppNotificationService;
use App\Services\OrderChatContactGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ContentRevisionService
{
    public function __construct(
        private InAppNotificationService $notifications,
        private OrderChatContactGuard $chatGuard,
    ) {}

    /**
     * Publisher asks the advertiser to revise / resend the article.
     *
     * @return array{item: OrderItem, order: Order, site: Site}
     */
    public function requestFromPublisher(OrderItem $item, User $publisher, string $reason): array
    {
        $reason = trim($reason);
        if (strlen($reason) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'Please explain what needs to change (at least 10 characters).',
            ]);
        }

        return DB::transaction(function () use ($item, $publisher, $reason) {
            $locked = OrderItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $site = Site::query()
                ->whereKey($locked->site_id)
                ->where('publisher_id', $publisher->id)
                ->first();

            if (! $site) {
                throw ValidationException::withMessages([
                    'order' => 'Unauthorized: This order does not belong to your site.',
                ]);
            }

            if ($order->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'order' => 'Order payment is not confirmed yet.',
                ]);
            }

            if ($order->status !== 'processing') {
                throw ValidationException::withMessages([
                    'order' => 'You can only request a revised article after accepting the order and before advertiser review.',
                ]);
            }

            if ($locked->isModificationRequested()) {
                throw ValidationException::withMessages([
                    'order' => 'There is already an open live-URL change request from the advertiser.',
                ]);
            }

            if (filled($locked->live_url) && in_array($order->status, ['review', 'completed'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'This order is already in advertiser review. Ask them to request live-URL changes instead.',
                ]);
            }

            $locked->update([
                'content_revision_requested' => 'yes',
                'content_revision_requested_at' => now(),
                'content_revision_reason' => $reason,
                'content_revision_resolved_at' => null,
            ]);

            $this->postChat(
                $order,
                $publisher->id,
                'publisher',
                "Revised article requested: {$reason}\nPlease upload or link an updated article for this placement."
            );

            return [
                'item' => $locked->fresh(),
                'order' => $order->fresh(),
                'site' => $site,
            ];
        });
    }

    /**
     * Notify advertiser after a successful publisher request (outside TX).
     */
    public function notifyAdvertiserRequested(Order $order, OrderItem $item, Site $site, string $reason): void
    {
        $advertiser = User::find($order->user_id);
        if ($advertiser?->email) {
            try {
                Mail::to($advertiser->email)->send(new ContentRevisionRequested($order, $item, $site, $reason));
            } catch (\Throwable $e) {
                Log::error('Failed to send content revision requested email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->notifications->notifyContentRevisionRequested($order, $item, $site, $reason);
        } catch (\Throwable $e) {
            Log::error('Failed to bell content revision requested', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Advertiser fulfills by linking a new URL and/or attaching a library article.
     *
     * @param  array{content_link?: string|null, content_submission_id?: int|null, note?: string|null, order_item_id?: int|null}  $payload
     * @return array{item: OrderItem, order: Order, site: Site}
     */
    public function fulfillFromAdvertiser(Order $order, User $advertiser, array $payload): array
    {
        if ((int) $order->user_id !== (int) $advertiser->id) {
            throw ValidationException::withMessages([
                'order' => 'Unauthorized',
            ]);
        }

        $contentLink = isset($payload['content_link']) ? trim((string) $payload['content_link']) : '';
        $submissionId = isset($payload['content_submission_id']) ? (int) $payload['content_submission_id'] : null;
        $note = isset($payload['note']) ? trim((string) $payload['note']) : '';
        $orderItemId = isset($payload['order_item_id']) ? (int) $payload['order_item_id'] : null;

        if ($contentLink === '' && ! $submissionId) {
            throw ValidationException::withMessages([
                'content_link' => 'Provide a content link or choose an approved Content Library article.',
            ]);
        }

        return DB::transaction(function () use ($order, $advertiser, $contentLink, $submissionId, $note, $orderItemId) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $itemQuery = OrderItem::query()
                ->where('order_id', $lockedOrder->id)
                ->where('content_revision_requested', 'yes')
                ->orderBy('id')
                ->lockForUpdate();

            if ($orderItemId) {
                $itemQuery->whereKey($orderItemId);
            }

            $item = $itemQuery->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    'order' => 'There is no open content revision request on this order.',
                ]);
            }

            if ($lockedOrder->status !== 'processing') {
                throw ValidationException::withMessages([
                    'order' => 'This order is no longer waiting for a revised article.',
                ]);
            }

            $update = [
                'content_revision_requested' => 'no',
                'content_revision_resolved_at' => now(),
            ];

            if ($submissionId) {
                $submission = ContentSubmission::query()
                    ->whereKey($submissionId)
                    ->where('user_id', $advertiser->id)
                    ->first();

                if (! $submission) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'Content Library article not found.',
                    ]);
                }

                if (! $submission->isApproved()) {
                    throw ValidationException::withMessages([
                        'content_submission_id' => 'Only approved Content Library articles can be attached.',
                    ]);
                }

                $update['content_submission_id'] = $submission->id;
                $update['content_disk'] = $submission->disk;
                $update['content_path'] = $submission->path;
                $update['content_original_name'] = $submission->original_filename;
                $update['content_mime'] = $submission->mime;
                $update['content_link'] = route('advertiser.content-submissions.download', $submission);
                if (filled($submission->anchor_text)) {
                    $update['anchor_text'] = $submission->anchor_text;
                }
                if (filled($submission->target_url)) {
                    $update['target_url'] = $submission->target_url;
                }
                if (filled($submission->feature_image_url)) {
                    $update['feature_image_url'] = $submission->feature_image_url;
                }
                if (filled($submission->moderation_status)) {
                    $update['moderation_status'] = $submission->moderation_status;
                }
            } elseif ($contentLink !== '') {
                $update['content_link'] = $contentLink;
                $update['content_submission_id'] = null;
                $update['content_disk'] = null;
                $update['content_path'] = null;
                $update['content_original_name'] = null;
                $update['content_mime'] = null;
            }

            $item->update($update);

            $site = Site::find($item->site_id);
            $chatBody = 'Revised article sent.'.($note !== '' ? "\nNote: {$note}" : '');
            $this->postChat($lockedOrder, $advertiser->id, 'advertiser', $chatBody);

            return [
                'item' => $item->fresh(),
                'order' => $lockedOrder->fresh(),
                'site' => $site,
            ];
        });
    }

    public function notifyPublisherFulfilled(Order $order, OrderItem $item, ?Site $site): void
    {
        if (! $site?->publisher_id) {
            return;
        }

        $publisher = User::find($site->publisher_id);
        if ($publisher?->email) {
            try {
                Mail::to($publisher->email)->send(new ContentRevisionFulfilled($order, $item, $site));
            } catch (\Throwable $e) {
                Log::error('Failed to send content revision fulfilled email', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            $this->notifications->notifyContentRevisionFulfilled($order, $item, $site);
        } catch (\Throwable $e) {
            Log::error('Failed to bell content revision fulfilled', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function postChat(Order $order, int $userId, string $senderType, string $body): void
    {
        try {
            $guard = $this->chatGuard->inspect($body);
            OrderChatMessage::create([
                'order_id' => $order->id,
                'user_id' => $userId,
                'sender_type' => $senderType,
                'message' => $body,
                'is_read' => false,
                'is_blocked' => (bool) $guard['blocked'],
                'blocked_reason' => $guard['blocked'] ? $guard['reason'] : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create content revision chat message', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
