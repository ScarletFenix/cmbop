<?php

namespace App\Services\Reminders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * When a placement should have gone live.
 *
 * Both reminder tracks and the admin stalled queue have to agree on this, or the
 * publisher gets chased on one clock while the advertiser is told about another.
 */
class OrderDeadline
{
    /**
     * Null means there is no promise to hold the publisher to — either the
     * listing has no turnaround time, or the order was never accepted.
     */
    public function for(OrderItem $item, ?Order $order = null, ?Site $site = null): ?Carbon
    {
        $order ??= $item->order;
        $site ??= $item->site;

        // A scheduled order runs on the date the advertiser asked for. A
        // publisher holding a post for a requested future date is not late.
        if ($order && $order->publication_mode === 'scheduled' && $order->scheduled_publish_at) {
            return $order->scheduled_publish_at->copy();
        }

        $hours = $site?->turnaroundHours();

        if (! $hours || ! $item->accepted_at) {
            return null;
        }

        return $item->accepted_at->copy()->addHours($hours);
    }

    /**
     * Hours past the deadline; negative while still inside the window.
     */
    public function hoursOverdue(OrderItem $item, ?Order $order = null, ?Site $site = null): ?int
    {
        $deadline = $this->for($item, $order, $site);

        return $deadline ? (int) $deadline->diffInHours(now(), false) : null;
    }
}
