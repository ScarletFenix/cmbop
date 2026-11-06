<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'managed_external_reference')) {
                $table->string('managed_external_reference', 500)->nullable()->after('managed_notes');
            }
        });
    }

    public function down(): void
    {
        // Hostinger skip-migration: keep columns.
    }
};
