<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Admin\FinanceOverviewService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(
        private FinanceOverviewService $finance,
    ) {}

    /**
     * Finance hub: period totals, liability, cash vs internal, ops queues.
     */
    public function index(Request $request)
    {
        $period = $this->finance->resolvePeriod(
            $request->get('period'),
            $request->get('date_from'),
            $request->get('date_to')
        );

        $data = $this->finance->overview($period);

        return view('admin.finance', [
            'data' => $data,
            'periodKey' => $period['key'],
            'dateFrom' => $request->get('date_from'),
            'dateTo' => $request->get('date_to'),
        ]);
    }

    /**
     * Browse wallet_transactions (global ledger).
     */
    public function ledger(Request $request)
    {
        $query = WalletTransaction::with(['user:id,name,email', 'wallet:id,role_id'])
            ->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('id', $search)
                    ->orWhereHas('user', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->paginate(40)->withQueryString();

        $types = [
            WalletTransaction::TYPE_DEPOSIT,
            WalletTransaction::TYPE_BONUS_CREDIT,
            WalletTransaction::TYPE_PURCHASE,
            WalletTransaction::TYPE_REFUND,
            WalletTransaction::TYPE_WITHDRAWAL,
            WalletTransaction::TYPE_ADJUSTMENT,
            WalletTransaction::TYPE_TRANSFER_OUT,
            WalletTransaction::TYPE_TRANSFER_IN,
        ];

        return view('admin.finance-ledger', compact('transactions', 'types'));
    }

    /**
     * Per-user money dossier.
     */
    public function user(User $user)
    {
        $dossier = $this->finance->userDossier($user);

        return view('admin.finance-user', ['dossier' => $dossier]);
    }

    /**
     * Period summary CSV for accounting.
     */
    public function export(Request $request): StreamedResponse
    {
        $period = $this->finance->resolvePeriod(
            $request->get('period'),
            $request->get('date_from'),
            $request->get('date_to')
        );
        $rows = $this->finance->exportRows($period);
        $filename = 'finance-'.$period['key'].'-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows, $period) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['period', 'section', 'metric', 'value']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $period['label'],
                    $row['section'],
                    $row['metric'],
                    $row['value'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
