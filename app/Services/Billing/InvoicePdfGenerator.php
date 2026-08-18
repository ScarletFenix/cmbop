<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InvoicePdfGenerator
{
    public function generateAndStore(Invoice $invoice): Invoice
    {
        $binary = $this->renderPdf($invoice)->output();

        $disk = (string) config('billing.storage.disk', 'local');
        $directory = trim((string) config('billing.storage.directory', 'invoices'), '/');
        $filename = sprintf(
            '%s/%s/%s-%s.pdf',
            $directory,
            now()->format('Y/m'),
            Str::slug($invoice->invoice_number),
            Str::lower(Str::random(8))
        );

        Storage::disk($disk)->put($filename, $binary);

        $invoice->update([
            'pdf_disk' => $disk,
            'pdf_path' => $filename,
        ]);

        return $invoice->fresh();
    }

    public function stream(Invoice $invoice)
    {
        if ($invoice->pdfExists()) {
            return Storage::disk($invoice->pdfStorageDisk())->response(
                $invoice->pdf_path,
                $invoice->invoice_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        return $this->renderPdf($invoice)->stream($invoice->invoice_number.'.pdf');
    }

    public function download(Invoice $invoice)
    {
        if ($invoice->pdfExists()) {
            return Storage::disk($invoice->pdfStorageDisk())->download(
                $invoice->pdf_path,
                $invoice->invoice_number.'.pdf',
                ['Content-Type' => 'application/pdf']
            );
        }

        return $this->renderPdf($invoice)->download($invoice->invoice_number.'.pdf');
    }

    public function absolutePath(Invoice $invoice): ?string
    {
        if (! $invoice->pdfExists()) {
            return null;
        }

        return Storage::disk($invoice->pdfStorageDisk())->path($invoice->pdf_path);
    }

    /**
     * Dompdf's CPDF adapter needs PHP GD to embed PNG logos. Hostinger /
     * this VM can lack gd — still produce the invoice, just without the raster.
     */
    private function renderPdf(Invoice $invoice)
    {
        $includeLogo = extension_loaded('gd');

        try {
            return $this->makePdf($invoice, $includeLogo);
        } catch (\Throwable $e) {
            if (! $includeLogo || ! $this->isMissingGdException($e)) {
                throw $e;
            }

            return $this->makePdf($invoice, false);
        }
    }

    private function makePdf(Invoice $invoice, bool $includeLogo)
    {
        $html = view('billing.pdf.invoice', [
            'invoice' => $invoice,
            'company' => config('billing.company'),
            'colors' => config('billing.colors'),
            'currencySymbol' => config('billing.currency_symbol', '€'),
            'includeLogo' => $includeLogo,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        // Force rasterization now so a missing-GD throw happens here,
        // not inside stream()/download() after headers may have started.
        $pdf->output();

        return $pdf;
    }

    private function isMissingGdException(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'GD extension')
            || str_contains($e->getMessage(), 'GD is required');
    }
}
