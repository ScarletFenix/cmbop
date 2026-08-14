<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'checkout_line_key')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('checkout_line_key', 96)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'checkout_line_key')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['checkout_line_key']);
            $table->dropColumn('checkout_line_key');
        });
    }
};
