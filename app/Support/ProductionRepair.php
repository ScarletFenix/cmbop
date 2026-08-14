<?php

namespace App\Support;

use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Hostinger steps this agent cannot SSH in to run: migrate, MEDIA_PATH,
 * public/storage, APP_URL, and roles. Safe to call from artisan or a web
 * request (locked by HealHostingerProduction).
 */
class ProductionRepair
{
    /**
     * @return list<string>
     */
    public function run(bool $persistEnv = true): array
    {
        $notes = [];

        $wroteEnv = false;

        $this->migrate($notes);
        $this->seedRoles($notes);
        $this->ensureMedia($notes, $persistEnv, $wroteEnv);
        $this->ensureAppUrl($notes, $persistEnv, $wroteEnv);
        $this->ensureStorageLink($notes);

        if ($wroteEnv) {
            $this->refreshCachedConfig();
        }

        return $notes;
    }

    /**
     * @param  list<string>  $notes
     */
    private function migrate(array &$notes): void
    {
        try {
            if ($this->needsOrderedBootstrap()) {
                foreach ($this->bootstrapMigrationFiles() as $file) {
                    Artisan::call('migrate', [
                        '--force' => true,
                        '--path' => 'database/migrations/'.$file,
                    ]);
                }
                $notes[] = 'bootstrapped users/sites/orders/order_items for Hostinger migrate order';
            }

            $code = Artisan::call('migrate', ['--force' => true]);
            $notes[] = $code === 0
                ? 'migrate --force completed'
                : 'migrate --force exited '.$code;
        } catch (\Throwable $e) {
            $notes[] = 'migrate failed: '.$e->getMessage();
            Log::error('Production repair migrate failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Filename timestamps are out of dependency order. A clean Hostinger
     * `migrate --force` fails unless users/sites/orders/order_items exist first.
     */
    private function needsOrderedBootstrap(): bool
    {
        try {
            if (! app('migrator')->repositoryExists()) {
                return true;
            }

            return ! Schema::hasTable('users')
                || ! Schema::hasTable('sites')
                || ! Schema::hasTable('orders')
                || ! Schema::hasTable('order_items');
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<string>
     */
    private function bootstrapMigrationFiles(): array
    {
        return [
            '0001_01_01_000000_create_users_table.php',
            '2026_04_06_094704_create_sites_table.php',
            '2026_04_21_070134_create_orders_table.php',
            '2026_04_21_070217_create_order_items_table.php',
        ];
    }

    /**
     * @param  list<string>  $notes
     */
    private function seedRoles(array &$notes): void
    {
        try {
            (new RolesTableSeeder)->run();
            $notes[] = 'roles seeded (advertiser, publisher, admin, marketing)';
        } catch (\Throwable $e) {
            $notes[] = 'roles seed failed: '.$e->getMessage();
            Log::error('Production repair roles seed failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $notes
     */
    private function ensureMedia(array &$notes, bool $persistEnv, bool &$wroteEnv): void
    {
        $path = HostingerMediaPath::ensure();
        if ($path === null) {
            $notes[] = 'MEDIA_PATH left unset (no Hostinger /home or public_html parent)';

            return;
        }

        HostingerMediaPath::applyRuntime($path);
        $wrote = $this->persistKey('MEDIA_PATH', $path, $persistEnv);
        $wroteEnv = $wroteEnv || $wrote;
        $notes[] = $wrote
            ? 'MEDIA_PATH set to '.$path
            : 'MEDIA_PATH using '.$path;
    }

    /**
     * @param  list<string>  $notes
     */
    private function ensureAppUrl(array &$notes, bool $persistEnv, bool &$wroteEnv): void
    {
        if (! app()->environment('production') && ! HostingerMediaPath::looksLikeHostinger()) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $loopback = $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');

        if (! $loopback) {
            return;
        }

        $fallback = rtrim((string) config('app.public_url'), '/');
        $fallbackHost = strtolower((string) (parse_url($fallback, PHP_URL_HOST) ?: ''));
        if ($fallback === '' || $fallbackHost === '' || in_array($fallbackHost, ['localhost', '127.0.0.1', '::1'], true)) {
            $notes[] = 'APP_URL still loopback; set PUBLIC_APP_URL or APP_URL to the public origin';

            return;
        }

        config(['app.url' => $fallback]);
        $wrote = $this->persistKey('APP_URL', $fallback, $persistEnv);
        $wroteEnv = $wroteEnv || $wrote;
        $notes[] = $wrote
            ? 'APP_URL written from PUBLIC_APP_URL ('.$fallback.')'
            : 'APP_URL runtime set from PUBLIC_APP_URL ('.$fallback.')';
    }

    private function persistKey(string $key, string $value, bool $persistEnv): bool
    {
        if (! $persistEnv || app()->runningUnitTests()) {
            return false;
        }

        if (! DotEnvWriter::set($key, $value)) {
            return false;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        return true;
    }

    private function refreshCachedConfig(): void
    {
        if (! is_file(base_path('bootstrap/cache/config.php'))) {
            return;
        }

        try {
            Artisan::call('config:clear');
        } catch (\Throwable $e) {
            Log::warning('Production repair could not clear config cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $notes
     */
    private function ensureStorageLink(array &$notes): void
    {
        $link = PublicStorageLink::ensure();
        if ($link['ok']) {
            $notes[] = $link['repaired']
                ? 'public/storage symlink repaired'
                : 'public/storage already correct';

            return;
        }

        $notes[] = $link['message'] ?? 'public/storage link failed';
    }
}
