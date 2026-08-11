<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which onboarding reminder steps have actually been sent so a missed
 * cron day can catch up without re-sending a step the user already got.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'deposit_reminder_day7_sent_at')) {
                $table->timestamp('deposit_reminder_day7_sent_at')->nullable()->after('new_sites_digest_sent_at');
            }
            if (! Schema::hasColumn('users', 'deposit_reminder_day14_sent_at')) {
                $table->timestamp('deposit_reminder_day14_sent_at')->nullable()->after('deposit_reminder_day7_sent_at');
            }
            if (! Schema::hasColumn('users', 'add_site_reminder_day3_sent_at')) {
                $table->timestamp('add_site_reminder_day3_sent_at')->nullable()->after('deposit_reminder_day14_sent_at');
            }
            if (! Schema::hasColumn('users', 'add_site_reminder_day7_sent_at')) {
                $table->timestamp('add_site_reminder_day7_sent_at')->nullable()->after('add_site_reminder_day3_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'deposit_reminder_day7_sent_at',
                'deposit_reminder_day14_sent_at',
                'add_site_reminder_day3_sent_at',
                'add_site_reminder_day7_sent_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
