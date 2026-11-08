<?php

namespace App\Console\Commands;

use App\Models\EmailNotificationSetting;
use App\Services\WeeklyNewSites\DigestDispatcher;
use App\Services\WeeklyNewSites\PermissionGate;
use Illuminate\Console\Command;

class SendWeeklyNewSitesDigest extends Command
{
    protected $signature = 'emails:send-weekly-new-sites-digest
                            {--dry-run : Report matches without queueing mail}
                            {--limit=500 : Maximum advertisers to consider}
                            {--user= : Restrict to one user id}';

    protected $description = 'Queue the personalised weekly new-site digest for eligible advertisers';

    public function handle(DigestDispatcher $dispatcher): int
    {
        if (! $dispatcher->tablesReady()) {
            $this->warn('Weekly new-site digest tables are missing — run migrations or database/sql/add_weekly_new_sites_digest.sql.');

            return self::SUCCESS;
        }

        if (! EmailNotificationSetting::isEnabled(PermissionGate::TYPE)) {
            $this->info('Weekly new-site digest is off (admin toggle).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;

        $result = $dispatcher->run($dryRun, $limit, $userId > 0 ? $userId : null);

        $this->info(sprintf(
            '%sWeek %s: queued %d; skipped thin %d; skipped permission %d; other %d.%s',
            $dryRun ? '[dry run] ' : '',
            $result['week_key'],
            $result['queued'],
            $result['skipped_thin'],
            $result['skipped_permission'],
            $result['skipped_other'],
            $result['notes'] !== '' ? ' '.$result['notes'] : ''
        ));

        return self::SUCCESS;
    }
}
