<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'content_revision_requested')) {
                $table->string('content_revision_requested', 10)->default('no')->after('modification_requested_at');
            }
            if (! Schema::hasColumn('order_items', 'content_revision_requested_at')) {
                $table->timestamp('content_revision_requested_at')->nullable()->after('content_revision_requested');
            }
            if (! Schema::hasColumn('order_items', 'content_revision_reason')) {
                $table->text('content_revision_reason')->nullable()->after('content_revision_requested_at');
            }
            if (! Schema::hasColumn('order_items', 'content_revision_resolved_at')) {
                $table->timestamp('content_revision_resolved_at')->nullable()->after('content_revision_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'content_revision_resolved_at',
                'content_revision_reason',
                'content_revision_requested_at',
                'content_revision_requested',
            ] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
