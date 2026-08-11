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
            if (! Schema::hasColumn('sites', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'archived_at')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
