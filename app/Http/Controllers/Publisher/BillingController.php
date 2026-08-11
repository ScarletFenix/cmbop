<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()
            ->where('user_id', auth()->id())
            ->where('type', Invoice::TYPE_WITHDRAWAL_PAYOUT);

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('invoice_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('invoice_date', '<=', $request->to);
        }

        $documents = $query->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();

        return view('publisher.billing.index', compact('documents'));
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);

        return view('publisher.billing.show', compact('invoice'));
    }

    public function download(Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);
        abort_unless($invoice->type === Invoice::TYPE_WITHDRAWAL_PAYOUT, 404);

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
}
