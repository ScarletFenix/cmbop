<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clipboard harvest strikes on the advertiser catalog.
 *
 * Strike 1 warns and leaves full name + URL visible. Strike 2 sets
 * catalog_hide_until (24h) — Phase 3 consumes that flag for dual-mask UX.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'catalog_copy_strike_count')) {
                $table->unsignedTinyInteger('catalog_copy_strike_count')->default(0)->after('catalog_reveal_exempt_until');
            }
            if (! Schema::hasColumn('users', 'catalog_copy_warned_at')) {
                $table->timestamp('catalog_copy_warned_at')->nullable()->after('catalog_copy_strike_count');
            }
            if (! Schema::hasColumn('users', 'catalog_hide_until')) {
                $table->timestamp('catalog_hide_until')->nullable()->after('catalog_copy_warned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['catalog_hide_until', 'catalog_copy_warned_at', 'catalog_copy_strike_count'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
