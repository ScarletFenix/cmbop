<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\Schema;

/**
 * Hostinger / production preflight: the settings that make register, verify
 * email, catalog images, wallet checkout, and chat mail actually work.
 *
 * Local and PHPUnit stay quiet (SQLite, empty MEDIA_PATH, loopback APP_URL
 * are expected). Production fails loudly so a skipped migrate or a leftover
 * localhost URL cannot look like a working site.
 */
class ProductionReadiness
{
    public const SEVERITY_OK = 'ok';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_FAIL = 'fail';

    /**
     * @return list<array{id: string, severity: string, title: string, detail: string, fix: string}>
     */
    public function checks(): array
    {
        return [
            $this->databaseCheck(),
            $this->appUrlCheck(),
            $this->mediaPathCheck(),
            $this->storageLinkCheck(),
            $this->uploadLimitsCheck(),
            $this->rolesCheck(),
            $this->migrationsCheck(),
            $this->mailDrainCheck(),
            $this->schedulerCheck(),
        ];
    }

    /**
     * Failures and warnings to show on the admin dashboard (production only).
     *
     * @return list<array{id: string, severity: string, title: string, detail: string, fix: string}>
     */
    public function dashboardAlerts(): array
    {
        if (! app()->environment('production')) {
            return [];
        }

        return array_values(array_filter(
            $this->checks(),
            fn (array $check) => in_array($check['severity'], [self::SEVERITY_FAIL, self::SEVERITY_WARN], true)
        ));
    }

    /**
     * @return list<array{id: string, severity: string, title: string, detail: string, fix: string}>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->checks(),
            fn (array $check) => $check['severity'] === self::SEVERITY_FAIL
        ));
    }

    /**
     * @return list<array{id: string, severity: string, title: string, detail: string, fix: string}>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->checks(),
            fn (array $check) => $check['severity'] === self::SEVERITY_WARN
        ));
    }

    public function isHealthy(bool $strict = false): bool
    {
        if ($this->failures() !== []) {
            return false;
        }

        return ! $strict || $this->warnings() === [];
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function databaseCheck(): array
    {
        $driver = Schema::getConnection()->getDriverName();
        $ok = in_array($driver, ['mysql', 'mariadb'], true);

        if ($ok) {
            return $this->item('database', self::SEVERITY_OK, 'Database', 'Using '.$driver.'.', '');
        }

        if ($this->isProduction()) {
            return $this->item(
                'database',
                self::SEVERITY_FAIL,
                'Database is not MySQL',
                'Connected with ['.$driver.']. Several migrations use MySQL ENUM/ALTER and will not match production.',
                'Set DB_CONNECTION=mysql in .env and point at MariaDB/MySQL. See AGENTS.md.'
            );
        }

        return $this->item(
            'database',
            self::SEVERITY_OK,
            'Database',
            'Using '.$driver.' (allowed outside production).',
            ''
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function appUrlCheck(): array
    {
        $url = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $loopback = $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');

        if (! $loopback) {
            return $this->item('app_url', self::SEVERITY_OK, 'APP_URL', $url, '');
        }

        if (! $this->isProduction()) {
            return $this->item('app_url', self::SEVERITY_OK, 'APP_URL', $url.' (loopback is fine locally).', '');
        }

        $fallback = rtrim((string) config('app.public_url'), '/');

        return $this->item(
            'app_url',
            self::SEVERITY_WARN,
            'APP_URL is still loopback',
            $url.' — verify-email and mail CTAs will use PUBLIC_APP_URL ('.$fallback.').',
            'Set APP_URL to the real public origin (https://your-domain) and run php artisan config:clear.'
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function mediaPathCheck(): array
    {
        $configured = config('filesystems.media_path');
        $path = is_string($configured) ? rtrim($configured, DIRECTORY_SEPARATOR) : '';

        if ($path === '') {
            if ($this->isProduction()) {
                return $this->item(
                    'media_path',
                    self::SEVERITY_FAIL,
                    'MEDIA_PATH is empty',
                    'Catalog and site images live under storage/app/public and will be wiped on the next code deploy.',
                    'Set MEDIA_PATH to an absolute path outside public_html (see docs/hostinger-media.md).'
                );
            }

            return $this->item(
                'media_path',
                self::SEVERITY_OK,
                'MEDIA_PATH',
                'Unset — using storage/app/public (local/CI).',
                ''
            );
        }

        if (! is_dir($path) || ! is_writable($path)) {
            return $this->item(
                'media_path',
                self::SEVERITY_FAIL,
                'MEDIA_PATH is not writable',
                $path,
                'Create the directory and give the PHP user write access, or clear MEDIA_PATH.'
            );
        }

        return $this->item('media_path', self::SEVERITY_OK, 'MEDIA_PATH', $path, '');
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function storageLinkCheck(): array
    {
        $target = rtrim(str_replace('\\', '/', (string) config('filesystems.disks.public.root')), '/');
        $link = public_path('storage');

        if (is_link($link)) {
            $current = str_replace('\\', '/', (string) readlink($link));
            if (PublicStorageLink::pathsEqual($current, $target)) {
                return $this->item('storage_link', self::SEVERITY_OK, 'public/storage', 'Symlink → '.$target, '');
            }

            return $this->item(
                'storage_link',
                $this->isProduction() ? self::SEVERITY_FAIL : self::SEVERITY_WARN,
                'public/storage points at the wrong folder',
                'Link is '.$current.'; disk root is '.$target.'. Catalog images will 404.',
                'Run: php artisan ops:production-ready --repair   (or php artisan media:ensure-link)'
            );
        }

        if (is_dir($link)) {
            $resolved = str_replace('\\', '/', (string) (realpath($link) ?: $link));
            if (PublicStorageLink::pathsEqual($resolved, $target)) {
                return $this->item('storage_link', self::SEVERITY_OK, 'public/storage', 'Directory is the disk root.', '');
            }
        }

        return $this->item(
            'storage_link',
            $this->isProduction() ? self::SEVERITY_FAIL : self::SEVERITY_WARN,
            'public/storage link is missing',
            'Catalog /storage/... URLs will 404. /media/... still streams from the disk.',
            'Run: php artisan ops:production-ready --repair   (or php artisan media:ensure-link)'
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function uploadLimitsCheck(): array
    {
        $upload = $this->iniBytes((string) ini_get('upload_max_filesize'));
        $post = $this->iniBytes((string) ini_get('post_max_size'));
        $needed = 10 * 1024 * 1024;
        $wanted = 64 * 1024 * 1024;
        $summary = 'upload_max_filesize='.(ini_get('upload_max_filesize') ?: 'unset')
            .' post_max_size='.(ini_get('post_max_size') ?: 'unset');

        if ($upload >= $wanted && $post >= $wanted) {
            return $this->item('uploads', self::SEVERITY_OK, 'PHP upload limits', $summary, '');
        }

        if ($upload < $needed || $post < $needed) {
            return $this->item(
                'uploads',
                $this->isProduction() ? self::SEVERITY_FAIL : self::SEVERITY_WARN,
                'PHP upload limits are below 10 MB',
                $summary.' — a 5 MB .docx is rejected as UPLOAD_ERR_INI_SIZE.',
                'In hPanel → PHP Configuration set upload_max_filesize=64M and post_max_size=64M. public/.user.ini already requests 64M.'
            );
        }

        return $this->item(
            'uploads',
            self::SEVERITY_WARN,
            'PHP upload limits are below 64M',
            $summary.' — article uploads work, but Hostinger should match the 64M request in .user.ini.',
            'In hPanel → PHP Configuration set upload_max_filesize=64M and post_max_size=64M.'
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function rolesCheck(): array
    {
        $required = ['advertiser', 'publisher', 'admin', 'marketing'];

        try {
            if (! Schema::hasTable('roles')) {
                return $this->item(
                    'roles',
                    self::SEVERITY_FAIL,
                    'roles table is missing',
                    'Registration cannot assign advertiser/publisher.',
                    'Run: php artisan migrate --force && php artisan db:seed --force'
                );
            }

            $present = Role::query()->whereIn('name', $required)->pluck('name')->all();
            $missing = array_values(array_diff($required, $present));
        } catch (\Throwable $e) {
            return $this->item(
                'roles',
                self::SEVERITY_FAIL,
                'Could not read roles',
                $e->getMessage(),
                'Run: php artisan migrate --force && php artisan db:seed --force'
            );
        }

        if ($missing === []) {
            return $this->item('roles', self::SEVERITY_OK, 'Roles', 'advertiser, publisher, admin, marketing are present.', '');
        }

        return $this->item(
            'roles',
            self::SEVERITY_FAIL,
            'Required roles are missing',
            'Missing: '.implode(', ', $missing).'. Registration returns “temporarily unavailable”.',
            'Run: php artisan ops:production-ready --repair   (or php artisan db:seed --force)'
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function migrationsCheck(): array
    {
        $pending = $this->pendingMigrationNames();

        if ($pending === []) {
            return $this->item('migrations', self::SEVERITY_OK, 'Migrations', 'No pending migrations.', '');
        }

        $preview = implode(', ', array_slice($pending, 0, 5));
        if (count($pending) > 5) {
            $preview .= ' (+'.(count($pending) - 5).' more)';
        }

        return $this->item(
            'migrations',
            $this->isProduction() ? self::SEVERITY_FAIL : self::SEVERITY_WARN,
            'Pending migrations',
            $preview,
            'Run: php artisan migrate --force'
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function mailDrainCheck(): array
    {
        $auto = (bool) config('email_notifications.auto_drain', true);
        $connection = (string) config('email_notifications.queue_connection', 'sync');

        if ($connection === 'sync') {
            return $this->item(
                'mail_drain',
                self::SEVERITY_OK,
                'Mail queue',
                'MAIL_QUEUE_CONNECTION=sync — mail sends inline.',
                ''
            );
        }

        if ($auto) {
            return $this->item(
                'mail_drain',
                self::SEVERITY_OK,
                'Mail queue',
                'Auto-drain is on (web traffic + mail:drain-queue). Connection: '.$connection.'.',
                ''
            );
        }

        return $this->item(
            'mail_drain',
            self::SEVERITY_WARN,
            'Mail auto-drain is off',
            'Verify emails and chat mail sit on the emails queue until a worker consumes them.',
            'Run php artisan queue:work --queue=default,emails, or set MAIL_QUEUE_AUTO_DRAIN=true.'
        );
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function schedulerCheck(): array
    {
        $secret = (string) config('app.cron_secret', '');
        $httpCron = strlen($secret) >= 32;

        if ($httpCron) {
            return $this->item(
                'scheduler',
                self::SEVERITY_OK,
                'Scheduler',
                'HTTP cron is enabled (CRON_SECRET ≥ 32). Hit /cron/run/{key} every minute.',
                ''
            );
        }

        if (! $this->isProduction()) {
            return $this->item(
                'scheduler',
                self::SEVERITY_OK,
                'Scheduler',
                'CRON_SECRET unset — use `php artisan schedule:run` locally or leave HTTP cron disabled.',
                ''
            );
        }

        return $this->item(
            'scheduler',
            self::SEVERITY_WARN,
            'Confirm the scheduler is running',
            'CRON_SECRET is empty, so /cron/run is disabled. Auto-approve, nudges, and mail:drain-queue need `* * * * * php artisan schedule:run`.',
            'Add a system cron for schedule:run, or set CRON_SECRET (≥ 32 chars) and hit /cron/run/{key} every minute. See docs/ops-mail-reminders.md.'
        );
    }

    /**
     * @return list<string>
     */
    private function pendingMigrationNames(): array
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return ['migration repository missing'];
            }

            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();

            return array_values(array_diff(array_keys($files), $ran));
        } catch (\Throwable $e) {
            return ['could not read status: '.$e->getMessage()];
        }
    }

    private function isProduction(): bool
    {
        return app()->environment('production');
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @return array{id: string, severity: string, title: string, detail: string, fix: string}
     */
    private function item(string $id, string $severity, string $title, string $detail, string $fix): array
    {
        return [
            'id' => $id,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
