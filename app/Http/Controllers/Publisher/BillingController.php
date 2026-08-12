<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()
            ->where('user_id', auth()->id())
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT)
            ->where('status', '!=', Invoice::STATUS_CANCELLED);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            });
        }

        $from = $this->parseDate($request->input('from'));
        $to = $this->parseDate($request->input('to'));

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from) {
            $query->whereDate('invoice_date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->whereDate('invoice_date', '<=', $to->toDateString());
        }

        $documents = $query->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();

        return view('publisher.billing.index', [
            'documents' => $documents,
            'filterFrom' => $from?->toDateString(),
            'filterTo' => $to?->toDateString(),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);

        return view('publisher.billing.show', compact('invoice'));
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);

        if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
            $pdfs->generateAndStore($invoice);
            $invoice->refresh();
        }

        $billing->recordDownload($invoice);

        return $pdfs->download($invoice);
    }

    public function viewPdf(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);
        abort_if($invoice->status === Invoice::STATUS_CANCELLED, 404);

        if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
            $pdfs->generateAndStore($invoice);
            $invoice->refresh();
        }

        $billing->recordDownload($invoice);

        return $pdfs->stream($invoice);
    }

    private function authorizeOwner(Invoice $invoice): void
    {
        if ((int) $invoice->user_id !== (int) auth()->id() && ! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
