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
            if (Schema::hasColumn('deposit_requests', 'stripe_payment_intent_id')
                && ! $this->hasIndex('deposit_requests', 'deposit_requests_stripe_payment_intent_id_unique')
                && ! $this->hasDuplicateStripeIds('stripe_payment_intent_id')) {
                $table->unique('stripe_payment_intent_id');
            }

            if (Schema::hasColumn('deposit_requests', 'stripe_session_id')
                && ! $this->hasIndex('deposit_requests', 'deposit_requests_stripe_session_id_unique')
                && ! $this->hasDuplicateStripeIds('stripe_session_id')) {
                $table->unique('stripe_session_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deposit_requests')) {
            return;
        }

        Schema::table('deposit_requests', function (Blueprint $table) {
            if ($this->hasIndex('deposit_requests', 'deposit_requests_stripe_payment_intent_id_unique')) {
                $table->dropUnique(['stripe_payment_intent_id']);
            }
            if ($this->hasIndex('deposit_requests', 'deposit_requests_stripe_session_id_unique')) {
                $table->dropUnique(['stripe_session_id']);
            }
        });
    }

    private function hasDuplicateStripeIds(string $column): bool
    {
        return DB::table('deposit_requests')
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
            $sm = Schema::getConnection()->getSchemaBuilder();
            if (method_exists($sm, 'hasIndex')) {
                return $sm->hasIndex($table, $index);
            }
        } catch (Throwable) {
            // Fall through to information_schema / sqlite probe.
        }

        try {
            $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes($table);
            foreach ($indexes as $row) {
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
