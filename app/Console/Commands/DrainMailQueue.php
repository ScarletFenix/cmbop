<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Platform mail is queued (PlatformMailable implements ShouldQueue), so nothing
 * reaches an inbox until a worker consumes the "emails" queue. Shared hosting
 * cannot keep `queue:work` resident, so the scheduler runs this each minute.
 */
class DrainMailQueue extends Command
{
    protected $signature = 'mail:drain-queue
                            {--max-time=55 : Seconds to keep working before exiting}
                            {--tries=3 : Attempts per job before it is marked failed}';

    protected $description = 'Deliver queued platform mail on hosts without a resident queue worker';

    public function handle(): int
    {
        if (! config('email_notifications.auto_drain')) {
            $this->info('Mail queue auto-drain is disabled (MAIL_QUEUE_AUTO_DRAIN=false).');

            return self::SUCCESS;
        }

        $connection = (string) config('email_notifications.queue_connection', 'sync');

        if ($connection === 'sync') {
            $this->info('Mail is sent synchronously; there is no queue to drain.');

            return self::SUCCESS;
        }

        if (! $this->backendReady($connection)) {
            $this->warn("Queue connection [{$connection}] is not ready; skipping drain.");

            return self::SUCCESS;
        }

        $queues = collect([config('email_notifications.queue', 'emails'), 'default'])
            ->filter()
            ->unique()
            ->implode(',');

        return $this->call('queue:work', [
            'connection' => $connection,
            '--queue' => $queues,
            '--stop-when-empty' => true,
            '--max-time' => (int) $this->option('max-time'),
            '--tries' => (int) $this->option('tries'),
        ]);
    }

    /**
     * A database queue on a deployment that skipped migrations has no jobs table;
     * running the worker there would only emit noise every minute.
     */
    private function backendReady(string $connection): bool
    {
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return true;
        }

        try {
            return Schema::hasTable((string) config("queue.connections.{$connection}.table", 'jobs'));
        } catch (\Throwable $e) {
            $this->warn('Queue backend check failed: '.$e->getMessage());

            return false;
        }
    }
}
