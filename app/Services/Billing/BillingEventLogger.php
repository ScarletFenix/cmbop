<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Invoice;
use App\Models\Order;

class BillingEventLogger
{
    public function log(
        string $eventType,
        ?Invoice $invoice = null,
        ?Order $order = null,
        ?int $userId = null,
        array $meta = []
    ): BillingEvent {
        $attributes = [
            'event_type' => $eventType,
            'invoice_id' => $invoice?->id,
            'order_id' => $order?->id ?? $invoice?->order_id,
            'user_id' => $userId ?? $invoice?->user_id ?? $order?->user_id,
            'meta' => $meta ?: null,
        ];

        if (! BillingEvent::tableAvailable()) {
            return BillingEvent::make($attributes);
        }

        try {
            return BillingEvent::create($attributes);
        } catch (\Throwable) {
            return BillingEvent::make($attributes);
        }
    }
}
