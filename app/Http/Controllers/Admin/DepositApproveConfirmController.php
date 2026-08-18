<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositRequest;
use App\Models\Wallet;
use App\Services\Wallet\ManualDepositAlreadyProcessedException;
use App\Services\Wallet\ManualDepositApprovalService;
use App\Support\UserFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Email one-click approve: signed GET shows a confirm page; POST credits via
 * ManualDepositApprovalService. Never credits on GET.
 */
class DepositApproveConfirmController extends Controller
{
    public function show(Request $request, DepositRequest $deposit)
    {
        if (! $this->hasValidApproveSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        try {
            $deposit->loadMissing('user');
        } catch (\Throwable) {
            $deposit->setRelation('user', null);
        }
        $canApprove = $deposit->isPending();
        try {
            $context = $this->walletContext($deposit, $canApprove);
        } catch (\Throwable $e) {
            Log::warning('Failed to build deposit approve confirm context: '.$e->getMessage(), [
                'deposit_id' => $deposit->id,
            ]);
            $context = [
                'currentBalance' => 0.0,
                'incomingAmount' => round((float) $deposit->amount, 2),
                'projectedBalance' => null,
                'priorDeposits' => collect(),
                'bonusBalance' => 0.0,
                'possibleDuplicate' => false,
                'duplicateMatches' => collect(),
            ];
        }

        return view('admin.deposits.approve-confirm', array_merge($context, [
            'deposit' => $deposit,
            'canApprove' => $canApprove,
            'confirmAction' => $request->fullUrl(),
        ]));
    }

    public function confirm(Request $request, DepositRequest $deposit, ManualDepositApprovalService $approvals)
    {
        if (! $this->hasValidApproveSignature($request)) {
            return $this->invalidSignatureResponse();
        }

        $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $approvals->approve(
                $deposit,
                $request->user(),
                $request->input('admin_notes')
            );

            $message = $result['message'];
            $fresh = $result['deposit']->fresh(['user']);
            $balance = $this->advertiserBalance((int) $fresh->user_id);
            if ($balance !== null) {
                $message .= ' New wallet balance: €'.number_format($balance, 2).'.';
            }

            return redirect()
                ->route('admin.deposits')
                ->with('success', $message);
        } catch (ManualDepositAlreadyProcessedException $e) {
            return redirect()
                ->route('admin.deposits')
                ->with('error', UserFacingError::message($e, 'This deposit was already processed.'));
        } catch (\Exception $e) {
            Log::error('Failed to approve deposit from email confirm link', [
                'deposit_id' => $deposit->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('admin.deposits')
                ->with('error', UserFacingError::message($e, 'Failed to approve deposit. Please try again.'));
        }
    }

    /**
     * @return array{
     *     currentBalance: float,
     *     incomingAmount: float,
     *     projectedBalance: float|null,
     *     priorDeposits: Collection<int, DepositRequest>,
     *     bonusBalance: float,
     *     possibleDuplicate: bool,
     *     duplicateMatches: Collection<int, DepositRequest>
     * }
     */
    protected function walletContext(DepositRequest $deposit, bool $canApprove): array
    {
        $wallet = $this->advertiserWallet((int) $deposit->user_id);
        $currentBalance = round((float) ($wallet?->balance ?? 0), 2);
        $bonusBalance = round((float) ($wallet?->bonus_balance ?? 0), 2);
        $incomingAmount = round((float) $deposit->amount, 2);

        $priorDeposits = DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id);
        if (DepositRequest::hasTableColumn('approved_at')) {
            $priorDeposits->orderByDesc('approved_at');
        }
        $priorDeposits = $priorDeposits
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $duplicateMatches = $canApprove
            ? $this->duplicateAmountMatches($deposit, $incomingAmount)
            : collect();

        return [
            'currentBalance' => $currentBalance,
            'incomingAmount' => $incomingAmount,
            'projectedBalance' => $canApprove ? round($currentBalance + $incomingAmount, 2) : null,
            'priorDeposits' => $priorDeposits,
            'bonusBalance' => $bonusBalance,
            'possibleDuplicate' => $duplicateMatches->isNotEmpty(),
            'duplicateMatches' => $duplicateMatches,
        ];
    }

    /**
     * Recent completed deposits for this advertiser with the same amount —
     * soft signal that the admin may be about to credit a transfer twice.
     *
     * @return Collection<int, DepositRequest>
     */
    protected function duplicateAmountMatches(DepositRequest $deposit, float $incomingAmount): Collection
    {
        $lookbackDays = max(1, (int) config('billing.deposit_approve_duplicate_lookback_days', 30));
        $since = now()->subDays($lookbackDays);

        $matches = DepositRequest::query()
            ->where('user_id', $deposit->user_id)
            ->where('status', 'completed')
            ->whereKeyNot($deposit->id)
            ->where('amount', $incomingAmount)
            ->where(function ($q) use ($since) {
                if (DepositRequest::hasTableColumn('approved_at')) {
                    $q->where('approved_at', '>=', $since)
                        ->orWhere(function ($inner) use ($since) {
                            $inner->whereNull('approved_at')
                                ->where('created_at', '>=', $since);
                        });
                } else {
                    $q->where('created_at', '>=', $since);
                }
            });
        if (DepositRequest::hasTableColumn('approved_at')) {
            $matches->orderByDesc('approved_at');
        }

        return $matches
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    protected function advertiserWallet(int $userId): ?Wallet
    {
        $roleId = Wallet::advertiserRoleId();
        if (! $roleId || $userId <= 0) {
            return null;
        }

        try {
            if (! Schema::hasTable('wallets')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return Wallet::query()
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->first();
    }

    protected function advertiserBalance(int $userId): ?float
    {
        $wallet = $this->advertiserWallet($userId);

        return $wallet ? round((float) $wallet->balance, 2) : null;
    }

    protected function hasValidApproveSignature(Request $request): bool
    {
        return $request->hasValidRelativeSignatureWhileIgnoring(signed_url_ignored_query_params());
    }

    protected function invalidSignatureResponse()
    {
        return redirect()
            ->route('admin.deposits')
            ->with('error', 'This approve link is invalid or has expired. Open Deposits and approve from the admin panel, or request a fresh email.');
    }
}
