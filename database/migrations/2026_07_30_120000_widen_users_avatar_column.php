<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `users` MODIFY `avatar` TEXT NULL');
        }
        // sqlite tests: TEXT affinity already; no-op
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'avatar')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE `users` MODIFY `avatar` VARCHAR(255) NULL');
        }
    }
};
