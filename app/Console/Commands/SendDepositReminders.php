<?php

namespace App\Console\Commands;

use App\Mail\DepositReminderMail;
use App\Services\AudienceInventoryService;
use App\Services\EmailNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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
            ? [(string) $stepFilter => $this->daysForStep((string) $stepFilter)]
            : [
                DepositReminderMail::STEP_DAY7 => 7,
                DepositReminderMail::STEP_DAY14 => 14,
            ];

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($steps as $step => $days) {
            $users = $this->eligibleQuery($inventory, $days)->get();
            $this->info(sprintf(
                '%s: %d eligible advertiser(s) (registered %s).',
                $step,
                $users->count(),
                now()->subDays($days)->toDateString()
            ));

            foreach ($users as $user) {
                if ($dryRun) {
                    $this->line("  [dry-run] {$user->id} {$user->email}");
                    $total++;

                    continue;
                }

                $emails->sendDepositReminder($user, $step);
                $total++;
            }
        }

        $this->info($dryRun
            ? "Dry run complete. {$total} eligible recipient(s)."
            : "Queued/sent {$total} deposit reminder(s).");

        return self::SUCCESS;
    }

    protected function daysForStep(string $step): int
    {
        return $step === DepositReminderMail::STEP_DAY7 ? 7 : 14;
    }

    protected function eligibleQuery(AudienceInventoryService $inventory, int $days): Builder
    {
        $dayStart = now()->subDays($days)->startOfDay();
        $dayEnd = (clone $dayStart)->endOfDay();

        return $inventory->queryAdvertisersNeverDeposited()
            ->whereNotNull('email_verified_at')
            ->whereBetween('created_at', [$dayStart, $dayEnd]);
    }
}
