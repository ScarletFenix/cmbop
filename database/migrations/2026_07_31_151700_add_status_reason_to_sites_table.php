<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'status_reason')) {
                $table->text('status_reason')->nullable()->after('onboarding_status');
            }
            if (! Schema::hasColumn('sites', 'status_reason_at')) {
                $table->timestamp('status_reason_at')->nullable()->after('status_reason');
            }
            if (! Schema::hasColumn('sites', 'status_reason_by')) {
                $table->foreignId('status_reason_by')
                    ->nullable()
                    ->after('status_reason_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'status_reason_by')) {
                $table->dropConstrainedForeignId('status_reason_by');
            }
            if (Schema::hasColumn('sites', 'status_reason_at')) {
                $table->dropColumn('status_reason_at');
            }
            if (Schema::hasColumn('sites', 'status_reason')) {
                $table->dropColumn('status_reason');
            }
        });
    }
};
