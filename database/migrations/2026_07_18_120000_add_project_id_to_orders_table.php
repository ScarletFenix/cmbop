<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'project_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('user_id')
                ->constrained('projects')
                ->nullOnDelete();

            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'project_id')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['user_id', 'project_id']);
            $table->dropColumn('project_id');
        });
    }
};
