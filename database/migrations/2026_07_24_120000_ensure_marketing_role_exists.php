<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing was originally added only via RolesTableSeeder. Production hosts
 * that never re-seed therefore have no `marketing` row, so admin role
 * assignment (firstOrFail) and marketing panel access both fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $exists = DB::table('roles')->where('name', 'marketing')->exists();
        if ($exists) {
            return;
        }

        DB::table('roles')->insert([
            'name' => 'marketing',
            'description' => 'Marketing staff: site review in the admin panel (no payments/users).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Do not delete the role — existing users may already be attached.
    }
};
