<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\BalanceTransfer;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BalanceController extends Controller
{
    /**
     * Display balance page for publisher.
     */
    public function index()
    {
        $user = auth()->user();
        $publisherWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::publisherRoleId())
            ->first();
        $advertiserWallet = Wallet::where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();

        $publisher = $publisherWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $advertiser = $advertiserWallet?->roleSnapshot() ?? Wallet::emptyRoleSnapshot();
        $minWithdrawalAmount = max(0.01, round((float) config('billing.withdrawal_min_amount', 20), 2));
        $canWithdraw = $publisher['debt'] <= 0 && $publisher['withdrawable'] >= $minWithdrawalAmount;
        $showAdvertiserWallet = $advertiserWallet !== null && $user->hasRole('advertiser');

        return view('publisher.balance', [
            'publisher' => $publisher,
            'advertiser' => $advertiser,
            'publisherBalance' => $publisher['spendable'],
            'advertiserBalance' => $advertiser['spendable'],
            'publisherDebt' => $publisher['debt'],
            'minWithdrawalAmount' => $minWithdrawalAmount,
            'canWithdraw' => $canWithdraw,
            'showAdvertiserWallet' => $showAdvertiserWallet,
        ]);
    }

    /**
     * Role-to-role transfers are disabled.
     */
    public function transferToAdvertiser(Request $request)
    {
        return response()->json([
            'success' => false,
            'code' => 'transfers_disabled',
            'message' => 'Role-to-role fund transfers have been disabled. Available funds can be spent on the marketplace or withdrawn. Bonus credit can only be used for purchases on this website.',
        ], 410);
    }

    /**
     * Get transfer history — leftover endpoint; the Balance page no longer lists transfers.
     */
    public function getTransferHistory(Request $request)
    {
        try {
            $userId = auth()->id();

            $transfers = BalanceTransfer::where('user_id', $userId)
                ->where('from_role', 'publisher')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'transfers' => $transfers->items(),
                'pagination' => [
                    'current_page' => $transfers->currentPage(),
                    'last_page' => $transfers->lastPage(),
                    'per_page' => $transfers->perPage(),
                    'total' => $transfers->total(),
                    'from' => $transfers->firstItem(),
                    'to' => $transfers->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching transfer history: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transfer history',
            ]);
        }
    }
}
