<?php

namespace App\Services\Billing;

use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

class InvoiceNumberGenerator
{
    /**
     * Allocate the next unique sequential invoice number: INV-2026-000001
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
     * Allocate the next deposit receipt number: RCT-2026-000001
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
