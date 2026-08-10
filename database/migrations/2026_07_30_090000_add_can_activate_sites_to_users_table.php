<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-marketer permission: admin can allow specific marketing users
 * to activate/deactivate websites awaiting approval.
 * Safe on live Hostinger — additive nullable/default boolean only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'can_activate_sites')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('can_activate_sites')->default(false)->after('active_role_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_activate_sites')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('can_activate_sites');
            });
        }
    }
};
