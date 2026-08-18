<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('deposit_requests', 'paypal_order_id')) {
                $table->string('paypal_order_id')->nullable()->index();
            }
            if (! Schema::hasColumn('deposit_requests', 'paypal_capture_id')) {
                $table->string('paypal_capture_id')->nullable();
            }
            if (! Schema::hasColumn('deposit_requests', 'paypal_response')) {
                $table->json('paypal_response')->nullable();
            }
        });

        $this->uniqueIfMissing(
            'deposit_requests',
            'paypal_capture_id',
            'deposit_requests_paypal_capture_id_unique'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if ($this->hasIndex('deposit_requests', 'deposit_requests_paypal_capture_id_unique')) {
                $table->dropUnique(['paypal_capture_id']);
            }
            if ($this->hasIndex('deposit_requests', 'deposit_requests_paypal_order_id_index')) {
                $table->dropIndex(['paypal_order_id']);
            }
        });

        $drop = array_values(array_filter([
            Schema::hasColumn('deposit_requests', 'paypal_order_id') ? 'paypal_order_id' : null,
            Schema::hasColumn('deposit_requests', 'paypal_capture_id') ? 'paypal_capture_id' : null,
            Schema::hasColumn('deposit_requests', 'paypal_response') ? 'paypal_response' : null,
        ]));

        if ($drop !== []) {
            Schema::table('deposit_requests', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
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
