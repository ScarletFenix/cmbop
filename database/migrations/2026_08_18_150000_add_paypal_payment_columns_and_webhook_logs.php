<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('paypal_webhook_logs')) {
            Schema::create('paypal_webhook_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('event_type');
                $table->json('payload');
                $table->boolean('processed')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'paypal_order_id')) {
                $table->string('paypal_order_id')->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'paypal_capture_id')) {
                $table->string('paypal_capture_id')->nullable();
            }
            if (! Schema::hasColumn('orders', 'paypal_refund_id')) {
                $table->string('paypal_refund_id')->nullable();
            }
            if (! Schema::hasColumn('orders', 'paypal_response')) {
                $table->json('paypal_response')->nullable();
            }
        });

        $this->uniqueIfMissing('orders', 'paypal_capture_id', 'orders_paypal_capture_id_unique');
        $this->uniqueIfMissing('orders', 'paypal_refund_id', 'orders_paypal_refund_id_unique');
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if ($this->hasIndex('orders', 'orders_paypal_capture_id_unique')) {
                    $table->dropUnique(['paypal_capture_id']);
                }
                if ($this->hasIndex('orders', 'orders_paypal_refund_id_unique')) {
                    $table->dropUnique(['paypal_refund_id']);
                }
                if ($this->hasIndex('orders', 'orders_paypal_order_id_index')) {
                    $table->dropIndex(['paypal_order_id']);
                }
            });

            $drop = array_values(array_filter([
                Schema::hasColumn('orders', 'paypal_order_id') ? 'paypal_order_id' : null,
                Schema::hasColumn('orders', 'paypal_capture_id') ? 'paypal_capture_id' : null,
                Schema::hasColumn('orders', 'paypal_refund_id') ? 'paypal_refund_id' : null,
                Schema::hasColumn('orders', 'paypal_response') ? 'paypal_response' : null,
            ]));

            if ($drop !== []) {
                Schema::table('orders', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }

        Schema::dropIfExists('paypal_webhook_logs');
    }

    private function uniqueIfMissing(string $table, string $column, string $index): void
    {
        if (! Schema::hasColumn($table, $column) || $this->hasIndex($table, $index)) {
            return;
        }

        if ($this->hasDuplicateValues($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->unique($column);
        });
    }

    private function hasDuplicateValues(string $table, string $column): bool
    {
        return DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasIndex(string $table, string $index): bool
    {
        try {
            foreach (Schema::getConnection()->getSchemaBuilder()->getIndexes($table) as $row) {
                if (($row['name'] ?? '') === $index) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
