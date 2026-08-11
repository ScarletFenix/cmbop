<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertiser_spend_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('monthly_limit', 12, 2)->nullable();
            $table->unsignedTinyInteger('warn_at_percent')->default(80);
            $table->decimal('low_balance_threshold', 12, 2)->nullable();
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_bell')->default(true);
            $table->string('last_warn_period', 7)->nullable(); // Y-m
            $table->string('last_hit_period', 7)->nullable();
            $table->date('last_low_balance_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertiser_spend_budgets');
    }
};
