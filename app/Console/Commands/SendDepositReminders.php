<?php

namespace App\Console\Commands;

use App\Mail\DepositReminderMail;
use App\Services\AudienceInventoryService;
use App\Services\EmailNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class SendDepositReminders extends Command
{
    protected $signature = 'emails:send-deposit-reminders
                            {--dry-run : List eligible users without sending}
                            {--step= : Limit to day7 or day14}';

    protected $description = 'Send day-7 and day-14 deposit reminders to advertisers who never completed a deposit';

    public function handle(AudienceInventoryService $inventory, EmailNotificationService $emails): int
    {
        $stepFilter = $this->option('step');
        if ($stepFilter !== null && $stepFilter !== '' && ! in_array($stepFilter, [
            DepositReminderMail::STEP_DAY7,
            DepositReminderMail::STEP_DAY14,
        ], true)) {
            $this->error('Invalid --step. Use day7 or day14.');

            return self::FAILURE;
        }

        $steps = $stepFilter
            ? [(string) $stepFilter]
            : [
                DepositReminderMail::STEP_DAY7,
                DepositReminderMail::STEP_DAY14,
            ];

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($steps as $step) {
            $window = $this->windowForStep($step);
            $users = $this->eligibleQuery($inventory, $step, $window['min_days'], $window['max_days'])->get();
            $this->info(sprintf(
                '%s: %d eligible advertiser(s) (age %d–%d days, step not yet sent).',
                $step,
                $users->count(),
                $window['min_days'],
                $window['max_days']
            ));

            foreach ($users as $user) {
                if ($dryRun) {
                    $this->line("  [dry-run] {$user->id} {$user->email}");
                    $total++;

                    continue;
                }

                if ($emails->sendDepositReminder($user, $step)) {
                    $total++;
                }
            }
        }

        $this->info($dryRun
            ? "Dry run complete. {$total} eligible recipient(s)."
            : "Queued/sent {$total} deposit reminder(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{min_days: int, max_days: int}
     */
    protected function windowForStep(string $step): array
    {
        $key = $step === DepositReminderMail::STEP_DAY7 ? 'deposit_day7' : 'deposit_day14';
        $defaults = $step === DepositReminderMail::STEP_DAY7
            ? ['min_days' => 7, 'max_days' => 13]
            : ['min_days' => 14, 'max_days' => 45];

        return [
            'min_days' => max(1, (int) config("reminders.onboarding.{$key}.min_days", $defaults['min_days'])),
            'max_days' => max(1, (int) config("reminders.onboarding.{$key}.max_days", $defaults['max_days'])),
        ];
    }

    protected function eligibleQuery(
        AudienceInventoryService $inventory,
        string $step,
        int $minDays,
        int $maxDays,
    ): Builder {
        $oldest = now()->subDays($maxDays)->startOfDay();
        $newest = now()->subDays($minDays)->endOfDay();
        $sentColumn = $step === DepositReminderMail::STEP_DAY7
            ? 'deposit_reminder_day7_sent_at'
            : 'deposit_reminder_day14_sent_at';

        $query = $inventory->queryAdvertisersNeverDeposited()
            ->whereEmailVerified()
            ->where('created_at', '>=', $oldest)
            ->where('created_at', '<=', $newest);

        if (Schema::hasColumn('users', $sentColumn)) {
            $query->whereOnboardingReminderUnsent($sentColumn);
        }

        return $query;
    }
}
