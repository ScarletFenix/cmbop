<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Log;

/**
 * Issues a non-tax payout statement PDF when a withdrawal is marked paid.
 *
 * Wallet funds were already debited on request; this document confirms the
 * external transfer. It uses the PAY- series so it never consumes INV numbers.
 */
class WithdrawalPayoutStatementService
{
    public function __construct(
        private InvoiceNumberGenerator $numbers,
        private InvoicePdfGenerator $pdfs,
        private BillingEventLogger $events,
    ) {}

    public function issue(Withdrawal $withdrawal): ?Invoice
    {
        if ($withdrawal->status !== 'completed') {
            return null;
        }

        $withdrawal->loadMissing('user');

        if (! $withdrawal->user) {
            return null;
        }

        if ($existing = $this->find($withdrawal)) {
            return $existing;
        }

        try {
            $statement = Invoice::create($this->payload($withdrawal));
            $this->pdfs->generateAndStore($statement);
            $this->events->log('withdrawal_payout_statement_generated', $statement, null, $withdrawal->user_id, [
                'withdrawal_id' => $withdrawal->id,
            ]);

            return $statement->fresh();
        } catch (\Throwable $e) {
            Log::error('Failed to generate withdrawal payout statement', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);
            $this->events->log('withdrawal_payout_statement_failed', null, null, $withdrawal->user_id, [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function find(Withdrawal $withdrawal): ?Invoice
    {
        return Invoice::query()
            ->where('user_id', $withdrawal->user_id)
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->where('reference_code', 'WD-'.$withdrawal->id)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Withdrawal $withdrawal): array
    {
        $user = $withdrawal->user;
        $gross = round((float) $withdrawal->amount, 2);
        $fee = round((float) ($withdrawal->fee ?? 0), 2);
        $net = round((float) ($withdrawal->net_amount ?? ($gross - $fee)), 2);
        $paidAt = $withdrawal->processed_at ?: now();

        $lineItems = [
            [
                'description' => 'Publisher withdrawal payout',
                'reference' => 'WD-'.$withdrawal->id,
                'quantity' => 1,
                'unit_price' => $gross,
                'line_total' => $gross,
            ],
        ];

        if ($fee > 0) {
            $lineItems[] = [
                'description' => 'Withdrawal fee',
                'reference' => 'WD-'.$withdrawal->id.'-fee',
                'quantity' => 1,
                'unit_price' => -$fee,
                'line_total' => -$fee,
            ];
        }

        return [
            'invoice_number' => $this->numbers->nextPayoutStatement(),
            'type' => Invoice::TYPE_WITHDRAWAL_PAYOUT,
            'status' => Invoice::STATUS_PAID,
            'user_id' => $withdrawal->user_id,
            'order_id' => null,
            'reference_code' => 'WD-'.$withdrawal->id,
            'currency' => config('billing.currency', 'EUR'),
            'subtotal' => $gross,
            'tax_amount' => 0,
            'discount_amount' => $fee,
            'total_amount' => $net,
            'tax_rate' => 0,
            'tax_label' => null,
            'payment_method' => $withdrawal->payment_method,
            'payment_status' => 'paid',
            'transaction_id' => 'WD-'.$withdrawal->id,
            'invoice_date' => $paidAt,
            'paid_at' => $paidAt,
            'customer_name' => $user->billing_name ?? $user->name,
            'customer_email' => $user->email,
            'billing_snapshot' => [
                'name' => $user->billing_name ?? $user->name,
                'email' => $user->email,
                'company' => $user->company_name ?? null,
                'address' => $user->address ?? null,
                'city' => $user->city ?? null,
                'state' => $user->state ?? null,
                'postal_code' => $user->postal_code ?? null,
                'country' => $user->country ?? null,
                'payment_details' => $withdrawal->payment_details,
            ],
            'line_items' => $lineItems,
            'pdf_disk' => config('billing.storage.disk', 'local'),
            'notes' => (string) config('billing.withdrawal_payout_note'),
            'meta' => [
                'withdrawal_id' => $withdrawal->id,
                'document' => 'withdrawal_payout',
                'gross_amount' => $gross,
                'fee' => $fee,
                'net_amount' => $net,
            ],
        ];
    }

    /**
     * Ops: create missing PAY statements for completed withdrawals.
     *
     * @return array{created: int, skipped: int, failed: int, invoice_ids: list<int>}
     */
    public function backfillMissing(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $withdrawals = Withdrawal::query()
            ->with('user')
            ->where('status', 'completed')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.user_id', 'withdrawals.user_id')
                    ->where('invoices.type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
                    ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
                    ->whereRaw("invoices.reference_code = CONCAT('WD-', withdrawals.id)");
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $ids = [];

        foreach ($withdrawals as $withdrawal) {
            if (! $withdrawal->user) {
                $skipped++;

                continue;
            }

            $statement = $this->issue($withdrawal);
            if ($statement) {
                $created++;
                $ids[] = (int) $statement->id;
            } else {
                $failed++;
            }
        }

        return compact('created', 'skipped', 'failed') + ['invoice_ids' => $ids];
    }
}
