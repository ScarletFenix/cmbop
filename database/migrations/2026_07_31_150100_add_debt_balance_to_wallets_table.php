<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            if (! Schema::hasColumn('wallets', 'debt_balance')) {
                $table->decimal('debt_balance', 12, 2)->default(0)->after('bonus_reserved');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            if (Schema::hasColumn('wallets', 'debt_balance')) {
                $table->dropColumn('debt_balance');
            }
        });
    }
};
