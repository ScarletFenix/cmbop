<?php

namespace App\Services\Advertiser;

use App\Mail\SpendBudgetAlertMail;
use App\Models\AdvertiserSpendBudget;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InAppNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Soft monthly spend budgets + low-balance alerts.
 * Budget uses committed spend (completed + in progress), Option A candles.
 */
class SpendBudgetService
{
    public function __construct(private AdvertiserSpendService $spend) {}

    public function forUser(User $user): ?AdvertiserSpendBudget
    {
        return AdvertiserSpendBudget::query()->where('user_id', $user->id)->first();
    }

    public function upsert(User $user, array $data): AdvertiserSpendBudget
    {
        return AdvertiserSpendBudget::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'monthly_limit' => isset($data['monthly_limit']) && $data['monthly_limit'] !== ''
                    ? round((float) $data['monthly_limit'], 2)
                    : null,
                'warn_at_percent' => (int) ($data['warn_at_percent'] ?? 80),
                'low_balance_threshold' => isset($data['low_balance_threshold']) && $data['low_balance_threshold'] !== ''
                    ? round((float) $data['low_balance_threshold'], 2)
                    : null,
                'notify_email' => (bool) ($data['notify_email'] ?? true),
                'notify_bell' => (bool) ($data['notify_bell'] ?? true),
            ]
        );
    }

    /**
     * @return array{
     *     has_budget: bool,
     *     monthly_limit: ?float,
     *     committed: float,
     *     percent: float,
     *     warn_at_percent: int,
     *     over_warn: bool,
     *     over_limit: bool,
     *     low_balance: bool,
     *     spendable: float
     * }
     */
    public function status(User $user): array
    {
        $budget = $this->forUser($user);
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();
        $summary = $this->spend->summary((int) $user->id, ['from' => $from, 'to' => $to]);
        $committed = $summary['committed'];

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('role_id', Wallet::advertiserRoleId())
            ->first();
        $spendable = round((float) ($wallet?->balance ?? 0), 2);

        $limit = $budget?->monthly_limit !== null ? (float) $budget->monthly_limit : null;
        $warnAt = (int) ($budget?->warn_at_percent ?? 80);
        $percent = ($limit && $limit > 0) ? round(($committed / $limit) * 100, 1) : 0.0;
        $lowThreshold = $budget?->low_balance_threshold !== null
            ? (float) $budget->low_balance_threshold
            : null;

        return [
            'has_budget' => (bool) $budget,
            'monthly_limit' => $limit,
            'committed' => $committed,
            'percent' => $percent,
            'warn_at_percent' => $warnAt,
            'over_warn' => $limit && $limit > 0 && $percent >= $warnAt,
            'over_limit' => $limit && $limit > 0 && $committed >= $limit,
            'low_balance' => $lowThreshold !== null && $spendable < $lowThreshold,
            'spendable' => $spendable,
            'low_balance_threshold' => $lowThreshold,
        ];
    }

    /**
     * Evaluate after checkout / refund. Soft alerts only — never blocks purchases.
     */
    public function evaluate(User $user): void
    {
        $budget = $this->forUser($user);
        if (! $budget) {
            return;
        }

        $status = $this->status($user);
        $period = now()->format('Y-m');

        try {
            if ($status['over_limit'] && $budget->last_hit_period !== $period) {
                $this->notify($user, $budget, 'hit', $status);
                $budget->update(['last_hit_period' => $period, 'last_warn_period' => $period]);
            } elseif ($status['over_warn'] && $budget->last_warn_period !== $period) {
                $this->notify($user, $budget, 'warn', $status);
                $budget->update(['last_warn_period' => $period]);
            }

            if ($status['low_balance']) {
                $today = now()->toDateString();
                if ($budget->last_low_balance_on?->toDateString() !== $today) {
                    $this->notify($user, $budget, 'low_balance', $status);
                    $budget->update(['last_low_balance_on' => $today]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Spend budget evaluate failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notify(User $user, AdvertiserSpendBudget $budget, string $kind, array $status): void
    {
        $messages = [
            'warn' => 'You have used '.$status['percent'].'% of your €'
                .number_format((float) $status['monthly_limit'], 2).' monthly spend budget (including in-progress orders).',
            'hit' => 'You reached your €'.number_format((float) $status['monthly_limit'], 2)
                .' monthly spend budget (including in-progress orders). Checkout is not blocked.',
            'low_balance' => 'Spendable balance (€'.number_format((float) $status['spendable'], 2)
                .') is below your €'.number_format((float) $status['low_balance_threshold'], 2).' alert threshold.',
        ];
        $body = $messages[$kind] ?? 'Spend budget update.';

        if ($budget->notify_bell) {
            try {
                app(InAppNotificationService::class)->notify(
                    $user,
                    'spend_budget_'.$kind,
                    'Spend budget',
                    $body,
                    [
                        'action_url' => route('advertiser.analytics'),
                        'action_label' => 'View spending',
                        'category' => 'billing',
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Spend budget bell failed: '.$e->getMessage());
            }
        }

        if ($budget->notify_email && $user->email) {
            try {
                Mail::to($user->email)->send(new SpendBudgetAlertMail($user, $kind, $status));
            } catch (\Throwable $e) {
                Log::warning('Spend budget email failed: '.$e->getMessage());
            }
        }
    }
}
