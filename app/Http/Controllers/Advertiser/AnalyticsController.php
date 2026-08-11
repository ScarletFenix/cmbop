<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Services\Advertiser\AdvertiserSpendService;
use App\Services\Advertiser\SpendBudgetService;
use App\Services\AdvertiserAnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function __construct(
        private AdvertiserAnalyticsService $analytics,
        private AdvertiserSpendService $spend,
        private SpendBudgetService $budgets,
    ) {}

    public function index(Request $request)
    {
        $view = $request->get('view', 'day');
        if (! in_array($view, ['order', 'day', 'month'], true)) {
            $view = 'day';
        }

        $dimension = $request->get('breakdown', 'payment_method');
        $range = [
            'from' => $request->get('from'),
            'to' => $request->get('to'),
        ];

        $analytics = $this->analytics->build($request->user(), $view, $range);
        $breakdown = $this->spend->breakdown((int) $request->user()->id, $dimension, $range);
        $budget = $this->budgets->forUser($request->user());
        $budgetStatus = $this->budgets->status($request->user());

        return view('advertiser.analytics', compact(
            'analytics',
            'view',
            'breakdown',
            'dimension',
            'budget',
            'budgetStatus',
            'range'
        ));
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $range = [
            'from' => $request->get('from'),
            'to' => $request->get('to'),
        ];
        $rows = $this->spend->exportRows((int) auth()->id(), $range);
        $filename = 'spend-export-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Date', 'Order', 'Reference', 'Site', 'Country', 'Category', 'Payment method',
                'Gross', 'Refund', 'Net', 'Spent (completed)', 'In progress',
                'Payment status', 'Order status', 'Sensitive type', 'Sensitive amount', 'Invoice',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['order_number'],
                    $row['reference'],
                    $row['site'],
                    $row['country'],
                    $row['category'],
                    $row['payment_method'],
                    number_format((float) $row['gross'], 2, '.', ''),
                    number_format((float) $row['refund'], 2, '.', ''),
                    number_format((float) $row['net'], 2, '.', ''),
                    number_format((float) $row['spent'], 2, '.', ''),
                    number_format((float) $row['in_progress'], 2, '.', ''),
                    $row['payment_status'],
                    $row['order_status'],
                    $row['sensitive_type'],
                    number_format((float) $row['sensitive_amount'], 2, '.', ''),
                    $row['invoice_number'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request)
    {
        $range = [
            'from' => $request->get('from'),
            'to' => $request->get('to'),
        ];
        $summary = $this->spend->summary((int) auth()->id(), $range);
        $rows = array_slice($this->spend->exportRows((int) auth()->id(), $range), 0, 200);
        $methods = $this->spend->breakdown((int) auth()->id(), 'payment_method', $range);

        $html = view('advertiser.analytics.export-pdf', [
            'summary' => $summary,
            'rows' => $rows,
            'methods' => $methods,
            'range' => $range,
            'user' => auth()->user(),
            'company' => config('billing.company'),
        ])->render();

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->download('spend-summary-'.now()->format('Y-m-d').'.pdf');
    }

    public function saveBudget(Request $request)
    {
        $data = $request->validate([
            'monthly_limit' => 'nullable|numeric|min:0|max:1000000',
            'warn_at_percent' => 'nullable|integer|min:1|max:100',
            'low_balance_threshold' => 'nullable|numeric|min:0|max:1000000',
            'notify_email' => 'sometimes|boolean',
            'notify_bell' => 'sometimes|boolean',
        ]);

        $this->budgets->upsert(auth()->user(), [
            'monthly_limit' => $data['monthly_limit'] ?? null,
            'warn_at_percent' => $data['warn_at_percent'] ?? 80,
            'low_balance_threshold' => $data['low_balance_threshold'] ?? null,
            'notify_email' => $request->boolean('notify_email'),
            'notify_bell' => $request->boolean('notify_bell'),
        ]);

        return back()->with('success', 'Spend budget saved.');
    }
}
