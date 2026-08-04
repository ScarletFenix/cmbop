<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trust is temporary.
 *
 * Staff can lift pace checks for a known heavy browser, but a permanent
 * exemption is how a compromised agency account stays invisible forever. One
 * hour is long enough to finish a shortlist and short enough that anything
 * still running after that goes back under the usual checks on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'catalog_reveal_exempt_until')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('catalog_reveal_exempt_until')->nullable()->after('catalog_reveal_exempt');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'catalog_reveal_exempt_until')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('catalog_reveal_exempt_until');
        });
    }
};
