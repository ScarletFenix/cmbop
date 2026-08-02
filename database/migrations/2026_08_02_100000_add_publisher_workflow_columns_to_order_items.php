<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The publisher accept/reject workflow columns were only ever created by
 * CheckoutSchemaService at checkout time, so a database built purely from
 * migrations never had them. Add them properly; the guards keep this a no-op
 * where the runtime patcher already ran.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'publisher_status',
        'accepted_at',
        'rejected_at',
        'completed_at',
        'rejection_reason',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'publisher_status')) {
                $table->string('publisher_status', 40)->nullable()->default('pending');
            }

            foreach (['accepted_at', 'rejected_at', 'completed_at'] as $column) {
                if (! Schema::hasColumn('order_items', $column)) {
                    $table->timestamp($column)->nullable();
                }
            }

            if (! Schema::hasColumn('order_items', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
