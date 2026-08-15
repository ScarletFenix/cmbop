<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', 'admin_notes')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('admin_notes')->nullable();
            });
        }

        if (! Schema::hasColumn('orders', 'payment_reference')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_reference', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'payment_reference')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('payment_reference');
            });
        }

        if (Schema::hasColumn('orders', 'admin_notes')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('admin_notes');
            });
        }
    }
};
