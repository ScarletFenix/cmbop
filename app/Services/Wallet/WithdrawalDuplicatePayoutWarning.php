<?php

namespace App\Services\Wallet;

use App\Models\Withdrawal;
use Illuminate\Support\Collection;

/**
 * Same-publisher + same-net mark-paid warning used by the email confirm
 * page and the admin payout queue (panel + batch).
 */
class WithdrawalDuplicatePayoutWarning
{
    public function lookbackDays(): int
    {
        return max(1, (int) config('billing.withdrawal_mark_paid_duplicate_lookback_days', 30));
    }

    /**
     * @return Collection<int, Withdrawal>
     */
    public function matches(Withdrawal $withdrawal): Collection
    {
        $ids = $this->matchIdsByWithdrawalId([$withdrawal])[(int) $withdrawal->id] ?? [];
        if ($ids === []) {
            return collect();
        }

        return Withdrawal::query()
            ->whereIn('id', $ids)
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  iterable<int, Withdrawal>  $withdrawals
     * @return array<int, list<int>> Open withdrawal id => matching paid ids
     */
    public function matchIdsByWithdrawalId(iterable $withdrawals): array
    {
        $actionable = collect($withdrawals)
            ->filter(fn ($row) => $row instanceof Withdrawal && $row->isActionable())
            ->values();

        if ($actionable->isEmpty()) {
            return [];
        }

        $since = now()->subDays($this->lookbackDays());
        $userIds = $actionable->pluck('user_id')->unique()->filter()->values();

        $paid = Withdrawal::query()
            ->whereIn('user_id', $userIds)
            ->where('status', 'completed')
            ->where(function ($q) use ($since) {
                $q->where(function ($inner) use ($since) {
                    $inner->whereProcessedAtIsRecorded()
                        ->where('processed_at', '>=', $since);
                })->orWhere(function ($inner) use ($since) {
                    // Leftover processed_at is not a paid clock — same as null.
                    // Bound created_at so leftover request stamps cannot fake
                    // a recent duplicate on SQLite string compare.
                    $inner->whereProcessedAtIsMissing()
                        ->where('created_at', '>=', $since)
                        ->where('created_at', '>=', Withdrawal::PLAUSIBLE_SQL_DATETIME_FLOOR)
                        ->where('created_at', '<=', Withdrawal::PLAUSIBLE_SQL_DATETIME_CEIL);
                });
            })
            ->orderByDesc('processed_at')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'net_amount']);

        $paidIdsByUserNet = [];
        foreach ($paid as $row) {
            $key = $this->netKey((int) $row->user_id, (float) $row->net_amount);
            $paidIdsByUserNet[$key][] = (int) $row->id;
        }

        $result = [];
        foreach ($actionable as $withdrawal) {
            $key = $this->netKey((int) $withdrawal->user_id, (float) $withdrawal->net_amount);
            $ids = array_values(array_filter(
                $paidIdsByUserNet[$key] ?? [],
                fn (int $id) => $id !== (int) $withdrawal->id
            ));
            $result[(int) $withdrawal->id] = array_slice($ids, 0, 5);
        }

        return $result;
    }

    private function netKey(int $userId, float $netAmount): string
    {
        return $userId.'|'.number_format(round($netAmount, 2), 2, '.', '');
    }
}
