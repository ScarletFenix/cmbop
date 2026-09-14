<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Support\UserFacingError;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BillingController extends Controller
{
    private const EXPORT_ROW_CAP = 2000;

    /**
     * @var list<string>
     */
    private const LIST_TYPES = [
        Invoice::TYPE_TAX_INVOICE,
        Invoice::TYPE_PAYMENT_RECEIPT,
        Invoice::TYPE_REFUND_RECEIPT,
        Invoice::TYPE_PAYMENT_FAILURE,
        Invoice::TYPE_DEPOSIT_RECEIPT,
    ];

    /**
     * @var list<string>
     */
    private const LIST_STATUSES = [
        Invoice::STATUS_PAID,
        Invoice::STATUS_ISSUED,
        Invoice::STATUS_PENDING,
        Invoice::STATUS_FAILED,
        Invoice::STATUS_REFUNDED,
        Invoice::STATUS_CANCELLED,
    ];

    public function index(Request $request)
    {
        $from = $this->parseDate($request->input('from'));
        $to = $this->parseDate($request->input('to'));
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        try {
            $invoices = $this->filteredQuery($request, $from, $to)
                ->latest('invoice_date')
                ->latest('id')
                ->paginate(20)
                ->withQueryString();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load invoices. Please refresh and try again.')
            );
            $invoices = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        }

        return view('advertiser.billing.index', [
            'invoices' => $invoices,
            'filterFrom' => $from?->toDateString(),
            'filterTo' => $to?->toDateString(),
            'pendingPayIns' => $this->pendingPayInCount(),
        ]);
    }

    public function export(Request $request): StreamedResponse|RedirectResponse
    {
        $from = $this->parseDate($request->input('from'));
        $to = $this->parseDate($request->input('to'));
        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        try {
            $rows = $this->filteredQuery($request, $from, $to)
                ->latest('invoice_date')
                ->latest('id')
                ->limit(self::EXPORT_ROW_CAP)
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.billing.index')
                ->with('error', UserFacingError::message($e, 'Unable to export invoices. Please try again.'));
        }

        $filename = 'billing-export-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'invoice_number',
                'type',
                'status',
                'invoice_date',
                'amount',
                'currency',
                'reference',
                'transaction_id',
                'payment_method',
            ]);
            foreach ($rows as $invoice) {
                fputcsv($out, [
                    $invoice->invoice_number,
                    $invoice->type,
                    $invoice->status,
                    optional($invoice->invoice_date)?->toDateString(),
                    number_format((float) $invoice->total_amount, 2, '.', ''),
                    $invoice->currency ?: 'EUR',
                    $invoice->referenceLabel(),
                    $invoice->transaction_id,
                    $invoice->payment_method,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwner($invoice);
        try {
            $invoice->load(['order.items', 'parentInvoice', 'childInvoices']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('advertiser.billing.index')
                ->with('error', UserFacingError::message($e, 'Unable to load that invoice.'));
        }

        return view('advertiser.billing.show', compact('invoice'));
    }

    public function resend(Request $request, Invoice $invoice, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        try {
            $result = $billing->resendInvoiceEmail($invoice);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return $this->leftoverJson($e, 'Unable to send that invoice. Please try again.');
            }

            report($e);

            return redirect()
                ->route('advertiser.billing.show', $invoice)
                ->with('error', UserFacingError::message($e, 'Unable to send that invoice. Please try again.'));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $result['ok'],
                'message' => $result['message'],
            ], $result['ok'] ? 200 : 422);
        }

        return redirect()
            ->route('advertiser.billing.show', $invoice)
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function download(Request $request, Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        if ($denied = $this->cancelledTaxInvoiceResponse($request, $invoice)) {
            return $denied;
        }

        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }

            $billing->recordDownload($invoice);

            return $pdfs->download($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return $this->leftoverJson($e, 'Unable to download that invoice.');
            }

            report($e);

            return redirect()
                ->route('advertiser.billing.show', $invoice)
                ->with('error', UserFacingError::message($e, 'Unable to download that invoice.'));
        }
    }

    public function viewPdf(Request $request, Invoice $invoice, InvoicePdfGenerator $pdfs, BillingDocumentService $billing)
    {
        $this->authorizeOwner($invoice);

        if ($denied = $this->cancelledTaxInvoiceResponse($request, $invoice)) {
            return $denied;
        }

        try {
            if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
                $pdfs->generateAndStore($invoice);
                $invoice->refresh();
            }

            $billing->recordDownload($invoice);

            return $pdfs->stream($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return $this->leftoverJson($e, 'Unable to open that invoice.');
            }

            report($e);

            return redirect()
                ->route('advertiser.billing.show', $invoice)
                ->with('error', UserFacingError::message($e, 'Unable to open that invoice.'));
        }
    }

    /**
     * @return Builder<Invoice>
     */
    private function filteredQuery(Request $request, ?Carbon $from, ?Carbon $to): Builder
    {
        $query = Invoice::query()
            ->where('user_id', auth()->id())
            ->whereIn('type', self::LIST_TYPES)
            ->with('order:id,order_number,reference_code');

        $search = search_text($request->input('search'));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('reference_code', 'like', "%{$search}%")
                    ->orWhere('transaction_id', 'like', "%{$search}%");
            });
        }

        $status = search_text($request->input('status'));
        if (in_array($status, self::LIST_STATUSES, true)) {
            $query->where('status', $status);
        }

        $type = search_text($request->input('type'));
        if (in_array($type, self::LIST_TYPES, true)) {
            $query->where('type', $type);
        }

        if ($from) {
            $query->whereDate('invoice_date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->whereDate('invoice_date', '<=', $to->toDateString());
        }

        return $query;
    }

    private function pendingPayInCount(): int
    {
        if (! DepositRequest::tableAvailable()) {
            return 0;
        }

        try {
            return (int) DepositRequest::query()
                ->where('user_id', auth()->id())
                ->where('status', 'pending')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = search_text($value);
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $raw));
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    private function cancelledTaxInvoiceResponse(Request $request, Invoice $invoice): Response|JsonResponse|null
    {
        if ($invoice->advertiserCanDownloadPdf() || ! $invoice->isCancelled() || ! $invoice->isTaxInvoice()) {
            return null;
        }

        return $this->leftoverDenied($request, 'This invoice has been cancelled.');
    }

    private function leftoverJson(\Throwable $e, string $fallback): JsonResponse
    {
        report($e);

        return response()->json([
            'success' => false,
            'message' => UserFacingError::message($e, $fallback),
        ], $e instanceof QueryException ? 503 : 500);
    }

    private function leftoverDenied(Request $request, string $message, int $status = 403): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        }

        if ($status === 404) {
            return Invoice::missingDocumentHtml();
        }

        abort($status, $message);
    }

    private function authorizeOwner(Invoice $invoice): void
    {
        if ((int) $invoice->user_id === (int) auth()->id() || auth()->user()?->isAdmin()) {
            return;
        }

        abort($this->leftoverDenied(request(), 'You cannot access that invoice.'));
    }
}
