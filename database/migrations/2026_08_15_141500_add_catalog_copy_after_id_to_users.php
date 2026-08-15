<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id cutoff for the next copy-strike wave.
 *
 * Strike 1 used to DELETE catalog_copy_events so MySQL second-precision
 * timestamps could not stall strike 2. That also erased the evidence the
 * admin queue needs. New copies are counted only when id > this value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'catalog_copy_after_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_copy_after_id')->nullable()->after('catalog_copy_warned_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'catalog_copy_after_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('catalog_copy_after_id');
        });
    }
};
