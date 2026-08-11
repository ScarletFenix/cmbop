<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->index();
            }
        });

        // Backfill: use the order's last update when the order is already completed.
        if (Schema::hasColumn('order_items', 'completed_at') && Schema::hasTable('orders')) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'mysql') {
                DB::statement('
                    UPDATE order_items
                    INNER JOIN orders ON orders.id = order_items.order_id
                    SET order_items.completed_at = orders.updated_at
                    WHERE order_items.completed_at IS NULL
                      AND orders.status = ?
                ', ['completed']);
            } else {
                // SQLite / others
                DB::table('order_items')
                    ->whereNull('completed_at')
                    ->whereIn('order_id', function ($q) {
                        $q->select('id')->from('orders')->where('status', 'completed');
                    })
                    ->orderBy('id')
                    ->chunkById(200, function ($items) {
                        foreach ($items as $item) {
                            $updatedAt = DB::table('orders')->where('id', $item->order_id)->value('updated_at');
                            if ($updatedAt) {
                                DB::table('order_items')
                                    ->where('id', $item->id)
                                    ->update(['completed_at' => $updatedAt]);
                            }
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasColumn('order_items', 'completed_at')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
