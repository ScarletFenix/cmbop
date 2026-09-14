<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\BillingDocumentService;
use App\Services\Billing\InvoicePdfGenerator;
use App\Services\Billing\WithdrawalPayoutStatementService;
use App\Support\UserFacingError;
use Carbon\Carbon;
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
    public function index(Request $request)
    {
        $from = $this->parseDate($request->input('from'));
        $to = $this->parseDate($request->input('to'));

        if ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        try {
            $query = Invoice::queryPayoutsForPublisherUser(auth()->user());

            $search = search_text($request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('reference_code', 'like', "%{$search}%")
                        ->orWhere('transaction_id', 'like', "%{$search}%");
                });
            }

            if ($from) {
                $query->whereDate('invoice_date', '>=', $from->toDateString());
            }

            if ($to) {
                $query->whereDate('invoice_date', '<=', $to->toDateString());
            }

            $documents = $query->latest('invoice_date')->latest('id')->paginate(20)->withQueryString();
        } catch (\Throwable $e) {
            report($e);
            session()->flash(
                'error',
                UserFacingError::message($e, 'Unable to load payout documents. Please refresh and try again.')
            );
            $documents = new LengthAwarePaginator([], 0, 20, 1, [
                'path' => $request->url(),
                'query' => $request->query(),
            ]);
        }

        return view('publisher.billing.index', [
            'documents' => $documents,
            'filterFrom' => $from?->toDateString(),
            'filterTo' => $to?->toDateString(),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $this->authorizePublisherPayout($invoice);

        try {
            return view('publisher.billing.show', compact('invoice'));
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('publisher.billing.index')
                ->with('error', UserFacingError::message($e, 'Unable to load that payout document.'));
        }
    }

    public function download(
        Request $request,
        Invoice $invoice,
        InvoicePdfGenerator $pdfs,
        BillingDocumentService $billing,
        WithdrawalPayoutStatementService $statements,
    ): StreamedResponse|RedirectResponse|JsonResponse {
        $this->authorizePublisherPayout($invoice);

        try {
            // normalizeLegacyFeeLineItems() clears pdf_path when it strips legacy fee lines.
            $invoice = $statements->normalizeLegacyFeeLineItems($invoice);

            if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
                try {
                    $pdfs->generateAndStore($invoice);
                    $invoice->refresh();
                } catch (\Throwable $e) {
                    report($e);
                    // Fall through — download() can still render a live PDF.
                }
            }

            $billing->recordDownload($invoice);

            return $pdfs->download($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->leftoverDocumentFailure($request, $e, $invoice, 'Unable to download that document.');
        }
    }

    public function viewPdf(
        Request $request,
        Invoice $invoice,
        InvoicePdfGenerator $pdfs,
        BillingDocumentService $billing,
        WithdrawalPayoutStatementService $statements,
    ): StreamedResponse|RedirectResponse|JsonResponse {
        $this->authorizePublisherPayout($invoice);

        try {
            $invoice = $statements->normalizeLegacyFeeLineItems($invoice);

            if (! $invoice->hasPdf() || ! $invoice->pdfExists()) {
                try {
                    $pdfs->generateAndStore($invoice);
                    $invoice->refresh();
                } catch (\Throwable $e) {
                    report($e);
                    // Fall through — stream() can still render a live PDF.
                }
            }

            $billing->recordDownload($invoice);

            return $pdfs->stream($invoice);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->leftoverDocumentFailure($request, $e, $invoice, 'Unable to open that document.');
        }
    }

    private function leftoverDocumentFailure(
        Request $request,
        \Throwable $e,
        Invoice $invoice,
        string $fallback,
    ): JsonResponse|RedirectResponse {
        report($e);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => UserFacingError::message($e, $fallback),
            ], $e instanceof QueryException ? 503 : 500);
        }

        return redirect()
            ->route('publisher.billing.show', $invoice)
            ->with('error', UserFacingError::message($e, $fallback));
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

        abort($this->leftoverDenied(request(), 'You cannot access that document.'));
    }

    private function authorizePublisherPayout(Invoice $invoice): void
    {
        $this->authorizeOwner($invoice);

        $owner = $invoice->user;
        if ($invoice->type !== Invoice::TYPE_WITHDRAWAL_PAYOUT
            || $invoice->status === Invoice::STATUS_CANCELLED
            || ! ($owner && $invoice->isPublisherPayoutFor($owner))) {
            abort($this->leftoverDenied(request(), 'Document not found.', 404));
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
}
