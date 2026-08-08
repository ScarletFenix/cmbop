<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'publisher_accepted_at')) {
                $table->timestamp('publisher_accepted_at')->nullable()->after('publisher_id');
            }
            if (! Schema::hasColumn('sites', 'assigned_by_user_id')) {
                $table->unsignedBigInteger('assigned_by_user_id')->nullable()->after('publisher_accepted_at');
            }
        });

        // Existing listings were already on the publisher's portal.
        if (Schema::hasColumn('sites', 'publisher_accepted_at')) {
            $now = now()->toDateTimeString();
            DB::table('sites')
                ->whereNull('publisher_accepted_at')
                ->update([
                    'publisher_accepted_at' => DB::raw("COALESCE(created_at, '{$now}')"),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'assigned_by_user_id')) {
                $table->dropColumn('assigned_by_user_id');
            }
            if (Schema::hasColumn('sites', 'publisher_accepted_at')) {
                $table->dropColumn('publisher_accepted_at');
            }
        });
    }
};
