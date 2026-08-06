<?php

namespace App\Services\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hostinger deploys often skip migrations. Without site_url_reveals the eye
 * still painted the domain in the browser, then a refresh remasked it and hide
 * returned "Open the address before you can hide it." Heal the table first so
 * reveal/hide stay sticky across reloads.
 */
class CatalogRevealSchema
{
    public function ensure(): void
    {
        $this->ensureRevealsTable();
        $this->ensureConcealedAtColumn();
    }

    private function ensureRevealsTable(): void
    {
        try {
            if (Schema::hasTable('site_url_reveals')) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Could not check site_url_reveals table', ['error' => $e->getMessage()]);

            return;
        }

        try {
            Schema::create('site_url_reveals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->string('source', 20)->default('catalog');
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('concealed_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'site_id']);
                $table->index(['user_id', 'created_at']);
            });
            Log::info('Created missing site_url_reveals table for sticky catalog eye');
            SiteUrlVisibility::forgetSchemaCache();
        } catch (\Throwable $e) {
            // Some hosts deny CREATE TABLE with FKs; fall back to raw DDL.
            try {
                if (Schema::hasTable('site_url_reveals')) {
                    SiteUrlVisibility::forgetSchemaCache();

                    return;
                }

                $driver = Schema::getConnection()->getDriverName();

                if ($driver === 'sqlite') {
                    DB::statement(
                        'CREATE TABLE IF NOT EXISTS site_url_reveals (
                            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                            user_id INTEGER NOT NULL,
                            site_id INTEGER NOT NULL,
                            source VARCHAR(20) NOT NULL DEFAULT \'catalog\',
                            ip_address VARCHAR(45) NULL,
                            concealed_at DATETIME NULL,
                            created_at DATETIME NULL,
                            updated_at DATETIME NULL,
                            UNIQUE (user_id, site_id)
                        )'
                    );
                } else {
                    DB::statement(
                        'CREATE TABLE IF NOT EXISTS `site_url_reveals` (
                            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                            `user_id` bigint unsigned NOT NULL,
                            `site_id` bigint unsigned NOT NULL,
                            `source` varchar(20) NOT NULL DEFAULT \'catalog\',
                            `ip_address` varchar(45) NULL,
                            `concealed_at` timestamp NULL,
                            `created_at` timestamp NULL,
                            `updated_at` timestamp NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `site_url_reveals_user_id_site_id_unique` (`user_id`, `site_id`),
                            KEY `site_url_reveals_user_id_created_at_index` (`user_id`, `created_at`)
                        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                    );
                }
                Log::info('Created missing site_url_reveals table via raw DDL');
                SiteUrlVisibility::forgetSchemaCache();
            } catch (\Throwable $inner) {
                Log::warning('Could not create site_url_reveals table', [
                    'error' => $inner->getMessage(),
                    'hint' => 'Run migration 2026_08_04_060000_create_site_url_reveals_table',
                ]);
            }
        }
    }

    private function ensureConcealedAtColumn(): void
    {
        try {
            if (! Schema::hasTable('site_url_reveals')) {
                return;
            }
            if (Schema::hasColumn('site_url_reveals', 'concealed_at')) {
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Could not check site_url_reveals.concealed_at', ['error' => $e->getMessage()]);

            return;
        }

        try {
            Schema::table('site_url_reveals', function (Blueprint $table) {
                $table->timestamp('concealed_at')->nullable()->after('ip_address');
            });
            Log::info('Added missing site_url_reveals.concealed_at for sticky hide');
            SiteUrlVisibility::forgetSchemaCache();
        } catch (\Throwable $e) {
            try {
                if (Schema::hasColumn('site_url_reveals', 'concealed_at')) {
                    SiteUrlVisibility::forgetSchemaCache();

                    return;
                }
            } catch (\Throwable) {
                // ignore
            }

            // after() is MySQL-ish; retry without position for SQLite / older drivers.
            try {
                Schema::table('site_url_reveals', function (Blueprint $table) {
                    $table->timestamp('concealed_at')->nullable();
                });
                SiteUrlVisibility::forgetSchemaCache();
            } catch (\Throwable $inner) {
                Log::warning('Could not add site_url_reveals.concealed_at', [
                    'error' => $inner->getMessage(),
                    'hint' => 'Run migration 2026_08_05_203000_add_concealed_at_to_site_url_reveals_table',
                ]);
            }
        }
    }
}
