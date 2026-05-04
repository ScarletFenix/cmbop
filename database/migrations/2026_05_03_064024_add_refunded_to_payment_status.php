<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRefundedToPaymentStatus extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Modify payment_status enum to include 'refunded'
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending'");
        });
        
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'live_url_submitted_at')) {
                $table->timestamp('live_url_submitted_at')->nullable()->after('live_url');
            }   
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending'");
        });
        
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('live_url_submitted_at');
        });
    }
}