<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'suspended_reason')) {
                $table->string('suspended_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('users', 'suspended_by')) {
                $table->unsignedBigInteger('suspended_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'suspended_by')) {
                $table->dropColumn('suspended_by');
            }
            if (Schema::hasColumn('users', 'suspended_reason')) {
                $table->dropColumn('suspended_reason');
            }
            if (Schema::hasColumn('users', 'suspended_at')) {
                $table->dropColumn('suspended_at');
            }
        });
    }
};
