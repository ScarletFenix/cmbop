<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_item_disputes')) {
            return;
        }

        Schema::create('order_item_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('open'); // open | upheld | dismissed
            $table->text('reason');
            $table->text('admin_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('publisher_debited', 12, 2)->nullable();
            $table->decimal('advertiser_credited', 12, 2)->nullable();
            $table->decimal('debt_created', 12, 2)->nullable();
            $table->timestamps();

            $table->index(['order_item_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_disputes');
    }
};
