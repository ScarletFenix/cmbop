<?php

namespace App\Support;

/**
 * Request-scoped flag so admin "Send notification" can generate invoices
 * without emailing the customer. BillingDocumentService checks this from
 * afterCommit hooks that resolve a different service instance.
 */
class BillingCustomerMailSuppressor
{
    private int $depth = 0;

    public function enable(): void
    {
        $this->depth++;
    }

    public function disable(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    public function suppressed(): bool
    {
        return $this->depth > 0;
    }
}
