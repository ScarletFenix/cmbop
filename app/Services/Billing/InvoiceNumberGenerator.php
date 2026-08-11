<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

class InvoiceNumberGenerator
{
    /**
     * Allocate the next unique sequential tax invoice number: INV-2026-000001
     */
    public function next(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.invoice_number.prefix', 'INV'),
            (int) config('billing.invoice_number.pad', 6),
            $year
        );
    }

    /**
     * Allocate the next deposit (wallet top-up) receipt number: RCT-2026-000001
     */
    public function nextReceipt(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.receipt_number.prefix', 'RCT'),
            (int) config('billing.receipt_number.pad', 6),
            $year
        );
    }

    /**
     * Payment receipt / payment-failure report number: RCPT-2026-000001
     * Kept out of the INV tax-invoice series.
     */
    public function nextPaymentReceipt(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.payment_receipt_number.prefix', 'RCPT'),
            (int) config('billing.payment_receipt_number.pad', 6),
            $year
        );
    }

    /**
     * Credit note / refund receipt number: CN-2026-000001
     */
    public function nextCreditNote(?int $year = null): string
    {
        return $this->nextInSeries(
            (string) config('billing.credit_note_number.prefix', 'CN'),
            (int) config('billing.credit_note_number.pad', 6),
            $year
        );
    }

    /**
     * Pick the correct series for an invoice document type.
     */
    public function nextForType(string $type, ?int $year = null): string
    {
        return match ($type) {
            Invoice::TYPE_DEPOSIT_RECEIPT => $this->nextReceipt($year),
            Invoice::TYPE_PAYMENT_RECEIPT,
            Invoice::TYPE_PAYMENT_FAILURE => $this->nextPaymentReceipt($year),
            Invoice::TYPE_REFUND_RECEIPT => $this->nextCreditNote($year),
            default => $this->next($year),
        };
    }

    /**
     * Each series keeps its own gap-free counter per calendar year.
     */
    private function nextInSeries(string $prefix, int $pad, ?int $year = null): string
    {
        $year = $year ?: (int) now()->format('Y');

        return DB::transaction(function () use ($year, $prefix, $pad) {
            $sequence = $this->lockSequence($prefix, $year);

            if (! $sequence) {
                InvoiceSequence::create([
                    'series' => $prefix,
                    'year' => $year,
                    'last_number' => 0,
                ]);
                $sequence = $this->lockSequence($prefix, $year);
            }

            $sequence->last_number = ((int) $sequence->last_number) + 1;
            $sequence->save();

            return sprintf(
                '%s-%d-%s',
                $prefix,
                $year,
                str_pad((string) $sequence->last_number, $pad, '0', STR_PAD_LEFT)
            );
        });
    }

    private function lockSequence(string $prefix, int $year): ?InvoiceSequence
    {
        return InvoiceSequence::query()
            ->where('series', $prefix)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
    }
}
