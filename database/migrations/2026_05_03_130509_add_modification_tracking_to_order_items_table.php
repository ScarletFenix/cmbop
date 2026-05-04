<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddModificationTrackingToOrderItemsTable extends Migration
{
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Add columns if they don't exist
            if (!Schema::hasColumn('order_items', 'modification_requested')) {
                $table->enum('modification_requested', ['no', 'yes'])->default('no')->after('live_url_submitted_at');
            }
            
            if (!Schema::hasColumn('order_items', 'modification_requested_at')) {
                $table->timestamp('modification_requested_at')->nullable()->after('modification_requested');
            }
            
            if (!Schema::hasColumn('order_items', 'auto_approve_triggered')) {
                $table->boolean('auto_approve_triggered')->default(false)->after('modification_requested_at');
            }
            
            if (!Schema::hasColumn('order_items', 'auto_approve_at')) {
                $table->timestamp('auto_approve_at')->nullable()->after('auto_approve_triggered');
            }
        });
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'modification_requested',
                'modification_requested_at',
                'auto_approve_triggered',
                'auto_approve_at'
            ]);
        });
    }
}