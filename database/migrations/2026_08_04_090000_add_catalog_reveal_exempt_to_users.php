<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets staff mark an account as a known heavy browser.
 *
 * Any pace-based check will eventually flag a genuine agency working through a
 * large shortlist. The exemption has to exist before the brake does, or the
 * first false positive is a customer being throttled on a Friday with nobody
 * able to do anything about it until Monday.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'catalog_reveal_exempt')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('catalog_reveal_exempt')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'catalog_reveal_exempt')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('catalog_reveal_exempt');
        });
    }
};
