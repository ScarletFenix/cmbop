<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddModificationRequestedToOrderItemsTable extends Migration
{
    public function up()
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'modification_requested')) {
                $table->enum('modification_requested', ['no', 'yes'])->default('no')->after('live_url_submitted_at');
            }
            if (!Schema::hasColumn('order_items', 'modification_requested_at')) {
                $table->timestamp('modification_requested_at')->nullable()->after('modification_requested');
            }
        });
    }

    public function down()
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['modification_requested', 'modification_requested_at']);
        });
    }
}