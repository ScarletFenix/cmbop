<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reference_code', 10)->nullable()->after('order_number');
            $table->string('stripe_session_id')->nullable()->after('reference_code');
            $table->string('stripe_payment_intent_id')->nullable()->after('stripe_session_id');
            $table->json('stripe_response')->nullable()->after('stripe_payment_intent_id');
            $table->timestamp('paid_at')->nullable()->after('stripe_response');
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'reference_code', 
                'stripe_session_id', 
                'stripe_payment_intent_id', 
                'stripe_response',
                'paid_at'
            ]);
        });
    }
};