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
            if (! Schema::hasColumn('order_items', 'fulfilment_source')) {
                $table->string('fulfilment_source', 32)->default('publisher')->after('content_source');
            }
            if (! Schema::hasColumn('order_items', 'managed_assignee_id')) {
                $table->unsignedBigInteger('managed_assignee_id')->nullable()->after('fulfilment_source');
            }
            if (! Schema::hasColumn('order_items', 'managed_internal_status')) {
                $table->string('managed_internal_status', 40)->nullable()->after('managed_assignee_id');
            }
            if (! Schema::hasColumn('order_items', 'managed_notes')) {
                $table->text('managed_notes')->nullable()->after('managed_internal_status');
            }
            if (! Schema::hasColumn('order_items', 'managed_marked_at')) {
                $table->timestamp('managed_marked_at')->nullable()->after('managed_notes');
            }
            if (! Schema::hasColumn('order_items', 'managed_completed_by')) {
                $table->unsignedBigInteger('managed_completed_by')->nullable()->after('managed_marked_at');
            }
        });
    }

    public function down(): void
    {
        // Hostinger skip-migration: keep columns.
    }
};
