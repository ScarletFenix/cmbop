<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff site edit uses a free-text link type. The original ENUM only allows
 * dofollow/nofollow and rejects values such as "Guest Post" on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'link_type')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE `sites` MODIFY `link_type` VARCHAR(64) NOT NULL DEFAULT 'dofollow'");
    }

    public function down(): void
    {
        // Intentionally left blank — narrowing can truncate live values.
    }
};
