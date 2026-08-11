<?php

namespace App\Console\Commands;

use App\Mail\PublisherAddSiteReminderMail;
use App\Services\AudienceInventoryService;
use App\Services\EmailNotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

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
            ? [(string) $stepFilter]
            : [
                PublisherAddSiteReminderMail::STEP_DAY3,
                PublisherAddSiteReminderMail::STEP_DAY7,
            ];

        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($steps as $step) {
            $window = $this->windowForStep($step);
            $users = $this->eligibleQuery($inventory, $step, $window['min_days'], $window['max_days'])->get();
            $this->info(sprintf(
                '%s: %d eligible publisher(s) (age %d–%d days, step not yet sent).',
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

                if ($emails->sendPublisherAddSiteReminder($user, $step)) {
                    $total++;
                }
            }
        }

        $this->info($dryRun
            ? "Dry run complete. {$total} eligible recipient(s)."
            : "Queued/sent {$total} publisher add-site reminder(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{min_days: int, max_days: int}
     */
    protected function windowForStep(string $step): array
    {
        $key = $step === PublisherAddSiteReminderMail::STEP_DAY3 ? 'publisher_day3' : 'publisher_day7';
        $defaults = $step === PublisherAddSiteReminderMail::STEP_DAY3
            ? ['min_days' => 3, 'max_days' => 6]
            : ['min_days' => 7, 'max_days' => 30];

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
        $sentColumn = $step === PublisherAddSiteReminderMail::STEP_DAY3
            ? 'add_site_reminder_day3_sent_at'
            : 'add_site_reminder_day7_sent_at';

        $query = $inventory->queryPublishersNoSites()
            ->whereNotNull('email_verified_at')
            ->where('created_at', '>=', $oldest)
            ->where('created_at', '<=', $newest);

        if (Schema::hasColumn('users', $sentColumn)) {
            $query->whereNull($sentColumn);
        }

        return $query;
    }
}
