<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Order;
use App\Models\Withdrawal;

class FinanceController extends Controller
{
    /**
     * Lightweight finance overview linking into existing Money pages.
     */
    public function index()
    {
        $pendingDeposits = DepositRequest::where('status', 'pending');
        $openWithdrawals = Withdrawal::whereIn('status', ['pending', 'processing']);
        $pendingPayments = Order::where(function ($q) {
            $q->whereNull('payment_status')
                ->orWhereNotIn('payment_status', ['paid', 'refunded']);
        })->whereIn('status', ['pending', 'processing', 'review']);

        $tiles = [
            'deposits' => [
                'label' => 'Pending deposits',
                'count' => (clone $pendingDeposits)->count(),
                'amount' => (float) (clone $pendingDeposits)->sum('amount'),
                'hint' => 'Advertiser bank / card top-ups waiting approval',
                'url' => route('admin.deposits', ['status' => 'pending']),
                'icon' => 'fa-wallet',
                'tone' => 'warning',
            ],
            'withdrawals' => [
                'label' => 'Open withdrawals',
                'count' => (clone $openWithdrawals)->count(),
                'amount' => (float) (clone $openWithdrawals)->sum('net_amount'),
                'hint' => 'Publisher payouts to send (pending + processing)',
                'url' => route('admin.withdrawals'),
                'icon' => 'fa-money-bill-wave',
                'tone' => 'danger',
            ],
            'payments' => [
                'label' => 'Order payments',
                'count' => (clone $pendingPayments)->count(),
                'amount' => (float) (clone $pendingPayments)->sum('total_amount'),
                'hint' => 'Orders still unpaid or not refunded',
                'url' => route('admin.payments'),
                'icon' => 'fa-money-bill',
                'tone' => 'info',
            ],
            'fees' => [
                'label' => 'Platform fees collected',
                'count' => Withdrawal::where('status', 'completed')->count(),
                'amount' => (float) Withdrawal::where('status', 'completed')->sum('fee'),
                'hint' => 'Withdrawal fees from completed payouts',
                'url' => route('admin.withdrawals').'?queue=history&status=completed',
                'icon' => 'fa-percent',
                'tone' => 'success',
            ],
        ];

        return view('admin.finance', compact('tiles'));
    }
}
