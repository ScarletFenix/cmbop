<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'verify_token')) {
                $table->string('verify_token', 80)->nullable()->after('verified');
            }
            if (! Schema::hasColumn('sites', 'verify_token_created_at')) {
                $table->timestamp('verify_token_created_at')->nullable()->after('verify_token');
            }
            if (! Schema::hasColumn('sites', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verify_token_created_at');
            }
            if (! Schema::hasColumn('sites', 'verify_method')) {
                $table->string('verify_method', 20)->nullable()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            foreach (['verify_method', 'verified_at', 'verify_token_created_at', 'verify_token'] as $column) {
                if (Schema::hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
