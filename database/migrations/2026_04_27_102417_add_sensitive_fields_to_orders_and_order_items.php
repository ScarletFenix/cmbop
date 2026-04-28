<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSensitiveFieldsToOrdersAndOrderItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add fields to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->string('sensitive_type')->nullable()->after('payment_method');
            $table->decimal('additional_price', 10, 2)->default(0)->after('sensitive_type');
        });

        // Add fields to order_items table
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sensitive_type')->nullable()->after('content_link');
            $table->decimal('additional_price', 10, 2)->default(0)->after('sensitive_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['sensitive_type', 'additional_price']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['sensitive_type', 'additional_price']);
        });
    }
}