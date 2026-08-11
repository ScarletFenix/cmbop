<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\ManualWithdrawalInvalidTransitionException;
use App\Services\Wallet\ManualWithdrawalSettlementService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Email one-click mark-paid: signed GET shows confirm UI; POST settles via
 * ManualWithdrawalSettlementService. Never marks paid on GET.
 */
class WithdrawalMarkPaidConfirmController extends Controller
{
    public function show(Request $request, Withdrawal $withdrawal)
    {
        if (! $this->hasValidSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        $withdrawal->loadMissing('user');
        $canMarkPaid = $withdrawal->isActionable();
        $context = $this->payoutContext($withdrawal, $canMarkPaid);

        return view('admin.withdrawals.mark-paid-confirm', array_merge($context, [
            'withdrawal' => $withdrawal,
            'canMarkPaid' => $canMarkPaid,
            'confirmAction' => $request->fullUrl(),
        ]));
    }

    public function confirm(Request $request, Withdrawal $withdrawal, ManualWithdrawalSettlementService $settlement)
    {
        if (! $this->hasValidSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        $request->validate([
            'notes' => 'nullable|string|max:2000',
        ]);

        try {
            $result = $settlement->markPaid(
                $withdrawal,
                $request->user(),
                $request->input('notes')
            );

            $message = $result['unchanged']
                ? $result['message']
                : 'Marked paid. Net €'.number_format((float) $result['withdrawal']->net_amount, 2).' confirmed for WD-'.$result['withdrawal']->id.'.';

            return redirect()
                ->route('admin.withdrawals')
                ->with('success', $message);
        } catch (ManualWithdrawalInvalidTransitionException $e) {
            return redirect()
                ->route('admin.withdrawals')
                ->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to mark withdrawal paid from email confirm link', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('admin.withdrawals')
                ->with('error', UserFacingError::message($e, 'Failed to mark withdrawal paid. Please try again.'));
        }
    }

    /**
     * @return array{
     *     currentBalance: float,
     *     priorPaid: Collection<int, Withdrawal>,
     *     possibleDuplicate: bool,
     *     duplicateMatches: Collection<int, Withdrawal>
     * }
     */
    protected function payoutContext(Withdrawal $withdrawal, bool $canMarkPaid): array
    {
        $wallet = $this->payoutWallet((int) $withdrawal->user_id);
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);

        $priorPaid = Withdrawal::query()
            ->where('user_id', $withdrawal->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($withdrawal->id)
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duplicateMatches = $canMarkPaid
            ? $this->duplicateAmountMatches($withdrawal)
            : collect();

        return [
            'currentBalance' => $currentBalance,
            'priorPaid' => $priorPaid,
            'possibleDuplicate' => $duplicateMatches->isNotEmpty(),
            'duplicateMatches' => $duplicateMatches,
        ];
    }

    /**
     * @return Collection<int, Withdrawal>
     */
    protected function duplicateAmountMatches(Withdrawal $withdrawal): Collection
    {
        $lookbackDays = max(1, (int) config('billing.withdrawal_mark_paid_duplicate_lookback_days', 30));
        $since = now()->subDays($lookbackDays);
        $net = round((float) $withdrawal->net_amount, 2);

        return Withdrawal::query()
            ->where('user_id', $withdrawal->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($withdrawal->id)
            ->where('net_amount', $net)
            ->where(function ($q) use ($since) {
                $q->where('processed_at', '>=', $since)
                    ->orWhere(function ($inner) use ($since) {
                        $inner->whereNull('processed_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    protected function payoutWallet(int $userId): ?Wallet
    {
        if ($userId <= 0) {
            return null;
        }

        $publisherRoleId = Wallet::publisherRoleId();
        if ($publisherRoleId) {
            $wallet = Wallet::query()
                ->where('user_id', $userId)
                ->where('role_id', $publisherRoleId)
                ->first();
            if ($wallet) {
                return $wallet;
            }
        }

        $advertiserRoleId = Wallet::advertiserRoleId();
        if ($advertiserRoleId) {
            return Wallet::query()
                ->where('user_id', $userId)
                ->where('role_id', $advertiserRoleId)
                ->first();
        }

        return null;
    }

    protected function hasValidSignature(Request $request): bool
    {
        return $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params());
    }

    protected function invalidSignatureResponse()
    {
        return redirect()
            ->route('admin.withdrawals')
            ->with('error', 'This mark-paid link is invalid or has expired. Open the payout queue and mark paid from the admin panel, or request a fresh email.');
    }
}
