<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Advertiser\AdvertiserSpendService;
use App\Services\EmailNotificationService;
use Illuminate\Console\Command;

class SendEmailDigests extends Command
{
    protected $signature = 'emails:send-digests {--type=weekly : weekly|monthly}';

    protected $description = 'Send weekly activity or monthly spending summary emails to advertisers';

    public function handle(EmailNotificationService $emails, AdvertiserSpendService $spend): int
    {
        $type = $this->option('type');
        $advertiserRole = Role::where('name', 'advertiser')->first();
        if (! $advertiserRole) {
            $this->warn('No advertiser role found.');

            return self::SUCCESS;
        }

        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $advertiserRole->id))
            ->whereNotNull('email')
            ->get();

        $sent = 0;
        foreach ($users as $user) {
            if ($type === 'monthly') {
                $from = now()->subMonth()->startOfMonth();
                $to = now()->subMonth()->endOfMonth();
                $summary = $spend->summary((int) $user->id, ['from' => $from, 'to' => $to]);

                if ($summary['gross_orders'] <= 0 && $summary['refunded_orders'] <= 0) {
                    continue;
                }

                $emails->sendMonthlySummary($user, [
                    'month_key' => $from->format('Y-m'),
                    'month_label' => $from->format('F Y'),
                    'spend' => $summary['net'],
                    'gross' => $summary['gross'],
                    'refunded' => $summary['refunded'],
                    'orders' => $summary['spent_orders'] + $summary['in_progress_orders'],
                    'aov' => $summary['aov_net'],
                ]);
                $sent++;
            } else {
                $from = now()->subWeek()->startOfWeek();
                $to = now()->subWeek()->endOfWeek();
                $summary = $spend->summary((int) $user->id, ['from' => $from, 'to' => $to]);

                if ($summary['gross_orders'] <= 0 && $summary['refunded_orders'] <= 0
                    && ($summary['spent_orders'] + $summary['in_progress_orders']) <= 0) {
                    continue;
                }

                $emails->sendWeeklySummary($user, [
                    'week_key' => $from->format('o-\WW'),
                    'orders' => $summary['spent_orders'] + $summary['in_progress_orders'] + $summary['refunded_orders'],
                    'spend' => $summary['net'],
                    'completed' => $summary['spent_orders'],
                    'in_progress' => $summary['in_progress'],
                    'refunded' => $summary['refunded'],
                ]);
                $sent++;
            }
        }

        $this->info("Queued {$sent} {$type} digest email(s).");

        return self::SUCCESS;
    }
}
