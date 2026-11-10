<?php

namespace App\Console\Commands;

use App\Services\InAppNotificationService;
use Illuminate\Console\Command;

/**
 * One-off cleanup: archive in-app order-task bells that no longer match live
 * order / order_item status. Idempotent. Does not write production unless run.
 *
 *   php artisan notifications:sync-order-actions
 *   php artisan notifications:sync-order-actions --dry-run
 */
class SyncOrderActionNotificationsCommand extends Command
{
    protected $signature = 'notifications:sync-order-actions
                            {--dry-run : Report how many stale bells would be archived without writing}';

    protected $description = 'Archive stale order-task in-app notifications so the bell matches live order state';

    public function handle(InAppNotificationService $notifications): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $notifications->reconcileAllOrderActionNotifications($dryRun);

        $this->info(sprintf(
            '%sOrder-task bells — scanned: %d, %s: %d',
            $dryRun ? '[dry run] ' : '',
            $result['scanned'],
            $dryRun ? 'would archive' : 'archived',
            $result['archived']
        ));

        return self::SUCCESS;
    }
}
