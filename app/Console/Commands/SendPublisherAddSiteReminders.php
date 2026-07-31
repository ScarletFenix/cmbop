<?php

namespace App\Console\Commands;

use App\Mail\PublisherAddSiteReminderMail;
use App\Services\AudienceInventoryService;
use App\Services\EmailNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendPublisherAddSiteReminders extends Command
{
    protected $signature = 'emails:send-publisher-add-site-reminders
                            {--dry-run : List eligible users without sending}
                            {--step= : Limit to day3 or day7}';

    protected $description = 'Send day-3 and day-7 add-website reminders to publishers with no sites';

    public function handle(AudienceInventoryService $inventory, EmailNotificationService $emails): int
    {
        $stepFilter = $this->option('step');
        if ($stepFilter !== null && $stepFilter !== '' && ! in_array($stepFilter, [
            PublisherAddSiteReminderMail::STEP_DAY3,
            PublisherAddSiteReminderMail::STEP_DAY7,
        ], true)) {
            $this->error('Invalid --step. Use day3 or day7.');

            return self::FAILURE;
        }

        $steps = $stepFilter
            ? [(string) $stepFilter => $this->daysForStep((string) $stepFilter)]
            : [
                PublisherAddSiteReminderMail::STEP_DAY3 => 3,
                PublisherAddSiteReminderMail::STEP_DAY7 => 7,
            ];

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($steps as $step => $days) {
            $users = $this->eligibleQuery($inventory, $days)->get();
            $this->info(sprintf(
                '%s: %d eligible publisher(s) (registered %s).',
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

                $emails->sendPublisherAddSiteReminder($user, $step);
                $total++;
            }
        }

        $this->info($dryRun
            ? "Dry run complete. {$total} eligible recipient(s)."
            : "Queued/sent {$total} publisher add-site reminder(s).");

        return self::SUCCESS;
    }

    protected function daysForStep(string $step): int
    {
        return $step === PublisherAddSiteReminderMail::STEP_DAY3 ? 3 : 7;
    }

    protected function eligibleQuery(AudienceInventoryService $inventory, int $days): Builder
    {
        $dayStart = now()->subDays($days)->startOfDay();
        $dayEnd = (clone $dayStart)->endOfDay();

        return $inventory->queryPublishersNoSites()
            ->whereNotNull('email_verified_at')
            ->whereBetween('created_at', [$dayStart, $dayEnd]);
    }
}
